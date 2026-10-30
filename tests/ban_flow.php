<?php
declare(strict_types=1);

// Compte bloqué : plus de profil, d'étal, de produits ni de message ; au déblocage, tout revient comme avant.
// Signalement classé : le bouton revient et on peut re-signaler. Tout est remis en état à la fin.
// Usage : php tests/ban_flow.php [URL]   (défaut http://127.0.0.1:8000)

use Axi\Core\Auth;
use Axi\Core\Database;
use Axi\Core\Ids;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$fails = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$fails): void {
    echo($ok ? '  OK    ' : '  ÉCHEC ') . $label . ($ok ? '' : "  → $detail") . "\n";
    $fails += $ok ? 0 : 1;
};
$jar = (string) tempnam(sys_get_temp_dir(), 'axi-ban');
$web = static function (string $path, ?array $post = null) use ($api, $jar): array {
    $ch = curl_init($api . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $html = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $html];
};
$csrf = static function (string $html): string {
    preg_match('/name="_csrf" value="([^"]*)"/', $html, $m);
    return $m[1] ?? '';
};
$json = static function (string $method, string $path, ?string $token, ?array $body = null) use ($api): array {
    $ch = curl_init($api . $path);
    $h = ['Accept: application/json'];
    if ($token !== null) {
        $h[] = "Authorization: Bearer $token";
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    $raw = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?? []];
};

// Une discussion entre deux comptes de test actifs, avec un étal actif
$row = Database::one(
    "SELECT c.id, c.client_id, c.stall_id, s.user_id AS merchant_id, s.status AS stall_status FROM conversations c JOIN stalls s ON s.id = c.stall_id
     JOIN users a ON a.id = c.client_id JOIN users b ON b.id = s.user_id
     WHERE a.status = 'active' AND b.status = 'active' AND s.status = 'active' AND a.email LIKE 'sim-%@example.com' AND b.email LIKE 'sim-%@example.com' ORDER BY c.id DESC LIMIT 1",
);
if ($row === null) {
    exit("Aucune discussion de test disponible.\n");
}
[$conv, $clientId, $merchantId, $stallId] = [(int) $row['id'], (int) $row['client_id'], (int) $row['merchant_id'], (int) $row['stall_id']];
$convKey = Ids::encode('conv', $conv);
$stallKey = Ids::encode('stl', $stallId);
$userKey = static fn (int $id): string => Ids::encode('usr', $id);
$startedAt = \Axi\Core\Clock::now();
$maxMsg = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM messages');
$maxNotif = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM notifications');
$maxReport = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM reports');
$followed = Database::value('SELECT 1 FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$clientId, $stallId]) !== null;
Database::run('INSERT IGNORE INTO stall_follows (user_id, stall_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$clientId, $stallId]);
$clientHash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$clientId]);
$msgCount = (int) Database::value('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$conv]);
[, $login] = $web('/pro/connexion');
$web('/pro/connexion', ['_csrf' => $csrf($login), 'email' => 'partenaire@example.com', 'password' => 'Partenaire-2026!']);
$tokens = static fn (): array => [Auth::issueToken($clientId, 'ban-flow-test'), Auth::issueToken($merchantId, 'ban-flow-test')];
$act = static function (string $path, array $fields = []) use ($web, $csrf): void {
    [, $page] = $web('/pro/clients');
    $web($path, $fields + ['_csrf' => $csrf($page)]);
};

try {
    [$clientT, $merchantT] = $tokens();
    echo "1. Avant le blocage\n";
    $check('l\'étal et le profil s\'ouvrent', $json('GET', "/v1/stalls/$stallKey", $clientT)[0] === 200 && $json('GET', '/v1/users/' . $userKey($merchantId) . '/profile', $clientT)[0] === 200);

    echo "\n2. Commerçant bloqué\n";
    $act("/pro/commercants/$stallId/suspendre");
    $check('compte et étal bloqués', Database::value('SELECT status FROM users WHERE id = ?', [$merchantId]) === 'suspended' && Database::value('SELECT status FROM stalls WHERE id = ?', [$stallId]) === 'suspended');
    $check('prévenu', (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND id > ?', [$merchantId, $maxNotif]) === 1);
    $check('profil introuvable', $json('GET', '/v1/users/' . $userKey($merchantId) . '/profile', $clientT)[0] === 404);
    $check('étal introuvable', $json('GET', "/v1/stalls/$stallKey", $clientT)[0] === 404);
    $check('nouvelle discussion impossible', $json('POST', "/v1/stalls/$stallKey/conversation", $clientT)[0] === 404);
    [$c, $b] = $json('POST', "/v1/conversations/$convKey/messages", $clientT, ['body' => 'test']);
    $check('message refusé', $c === 403 && ($b['error']['code'] ?? '') === 'ACCOUNT_UNAVAILABLE', json_encode($b));
    $cv = $json('GET', "/v1/conversations/$convKey/messages", $clientT)[1]['data']['conversation'] ?? [];
    $check('discussion : nom masqué, pas de photo ni de réponse', ($cv['other']['name'] ?? '') === 'Compte indisponible' && $cv['other']['avatar'] === null && $cv['can_write'] === false && $cv['other_unavailable'] === true, json_encode($cv));
    $check('historique conservé', (int) Database::value('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$conv]) === $msgCount);
    [, $page] = $web('/pro/commercants?state=suspended');
    $check('bouton Débloquer dans la liste Commerçants', str_contains($page, "/pro/commercants/$stallId/activer"));

    echo "\n3. Commerçant débloqué : tout revient\n";
    $act("/pro/commercants/$stallId/activer", ['back' => 'list']);
    [$clientT2, $merchantT] = $tokens();
    $check('étal et profil de retour', $json('GET', "/v1/stalls/$stallKey", $clientT)[0] === 200 && $json('GET', '/v1/users/' . $userKey($merchantId) . '/profile', $clientT)[0] === 200);
    $cv = $json('GET', "/v1/conversations/$convKey/messages", $clientT)[1]['data']['conversation'] ?? [];
    $check('discussion : vrai nom, réponse possible', ($cv['other']['name'] ?? '') !== 'Compte indisponible' && $cv['can_write'] === true, json_encode($cv));
    $check('message de nouveau possible', $json('POST', "/v1/conversations/$convKey/messages", $clientT, ['body' => 'retour'])[0] === 201);

    echo "\n4. Client bloqué\n";
    $act("/pro/clients/$clientId/suspendre", ['reason' => 'Test de blocage']);
    $check('profil introuvable pour le commerçant', $json('GET', '/v1/users/' . $userKey($clientId) . '/profile', $merchantT)[0] === 404);
    $check('message refusé', $json('POST', "/v1/conversations/$convKey/messages", $merchantT, ['body' => 'test'])[0] === 403);
    [$c, $b] = $json('GET', '/v1/me/badges', $clientT2);
    $check('déjà connecté : alerte avec le motif, pas « session expirée »', $c === 403 && ($b['error']['code'] ?? '') === 'ACCOUNT_SUSPENDED' && str_contains($b['error']['message'] ?? '', 'Test de blocage'), json_encode($b));
    $email = (string) Database::value('SELECT email FROM users WHERE id = ?', [$clientId]);
    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('Test-Blocage-1', PASSWORD_DEFAULT), $clientId]);
    [$c, $b] = $json('POST', '/v1/auth/login', null, ['email' => $email, 'password' => 'Test-Blocage-1']);
    $check('connexion : refus avec le motif', $c === 403 && ($b['error']['code'] ?? '') === 'ACCOUNT_SUSPENDED' && str_contains($b['error']['message'] ?? '', 'Test de blocage'), json_encode($b));
    $contact = (string) ($b['error']['support_email'] ?? '');
    $check('adresse de contact fournie et dans le message', $contact !== '' && str_contains($b['error']['message'], $contact), json_encode($b));
    $list = $json('GET', '/v1/merchant/clients', $merchantT)[1]['data']['clients'] ?? [];
    $mine = array_values(array_filter($list, static fn (array $c): bool => ($c['unavailable'] ?? false) === true));
    $check('marqué indisponible dans Mes clients', $mine !== [], json_encode(array_slice($list, 0, 2)));
    [, $page] = $web('/pro/clients?status=suspended');
    $check('bouton Débloquer dans la liste Utilisateurs', str_contains($page, "/pro/clients/$clientId/activer"));
    $act("/pro/clients/$clientId/activer", ['back' => 'list']);
    $check('débloqué : profil de retour', $json('GET', '/v1/users/' . $userKey($clientId) . '/profile', $merchantT)[0] === 200);
    $check('débloqué : la connexion remarche', $json('POST', '/v1/auth/login', null, ['email' => $email, 'password' => 'Test-Blocage-1'])[0] === 200);

    echo "\n5. Signalement annulé : le bouton revient\n";
    [$clientT] = $tokens();
    $path = '/v1/users/' . $userKey($merchantId);
    $check('premier signalement', $json('POST', "$path/report", $clientT, ['reason' => 'spam', 'details' => 'test'])[0] < 300);
    $check('bouton retiré (déjà signalé)', ($json('GET', "$path/profile", $clientT)[1]['data']['reported'] ?? false) === true);
    $id = (int) Database::value('SELECT MAX(id) FROM reports WHERE target_user_id = ?', [$merchantId]);
    [, $page] = $web('/pro/signalements');
    $web("/pro/signalements/$id/classer", ['_csrf' => $csrf($page)]);
    $check('classé : le bouton revient', ($json('GET', "$path/profile", $clientT)[1]['data']['reported'] ?? true) === false);
    $check('nouveau signalement possible', $json('POST', "$path/report", $clientT, ['reason' => 'fraud', 'details' => 'autre problème'])[0] < 300);
    $check('les deux signalements sont gardés', (int) Database::value('SELECT COUNT(*) FROM reports WHERE target_user_id = ? AND id > ?', [$merchantId, $maxReport]) === 2);
} finally {
    Database::run('UPDATE users SET status = \'active\', suspended_reason = NULL WHERE id IN (?, ?)', [$clientId, $merchantId]);
    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [$clientHash, $clientId]);
    Database::run('UPDATE stalls SET status = ? WHERE id = ?', [$row['stall_status'], $stallId]);
    Database::run('DELETE FROM messages WHERE conversation_id = ? AND id > ?', [$conv, $maxMsg]);
    Database::run('DELETE FROM notifications WHERE user_id IN (?, ?) AND id > ?', [$clientId, $merchantId, $maxNotif]);
    Database::run('DELETE FROM reports WHERE id > ?', [$maxReport]);
    if (!$followed) {
        Database::run('DELETE FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$clientId, $stallId]);
    }
    Database::run("DELETE FROM auth_tokens WHERE device = 'ban-flow-test' OR user_id = ? AND created_at > ?", [$clientId, $startedAt]);
    @unlink($jar);
}
echo $fails === 0 ? "\nTout est bon.\n" : "\n$fails échec(s).\n";
exit($fails === 0 ? 0 : 1);
