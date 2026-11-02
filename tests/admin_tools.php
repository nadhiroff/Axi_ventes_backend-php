<?php
declare(strict_types=1);

// Espace agent : historique des modifications de profil, blocage à tout moment (sans signalement) d'un client ou
// d'un commerçant, documents du commerçant à voir et télécharger.
// Usage : php tests/admin_tools.php [URL]   (défaut http://localhost:8000, env local, comptes de test de reset.sql)

use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$stamp = (string) time();
$failures = 0;
$checks = 0;
$tmp = sys_get_temp_dir() . "/axi-admin-$stamp";
@mkdir($tmp);
$jar = "$tmp/cookies";

function section(string $title): void
{
    echo "\n== $title\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  OK      ' : '  ÉCHEC   ') . $label . (!$ok && $detail !== '' ? "\n          " . mb_substr($detail, 0, 300) : '') . "\n";
}

function call(string $method, string $path, mixed $body = null, ?string $token = null, bool $multipart = false, bool $web = false): array
{
    global $api, $jar;
    $ch = curl_init($api . $path);
    $h = $web ? [] : ['Accept: application/json'];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($web) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }
    $headers = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        }]);
    $raw = (string) curl_exec($ch);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
}

function lastCode(string $email): ?string
{
    foreach (array_reverse(@file(BASE_PATH . '/storage/logs/codes.log', FILE_IGNORE_NEW_LINES) ?: []) as $line) {
        if (preg_match('/ ' . preg_quote($email, '/') . ' verify_email (\d{6})$/', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

/** @return array{0: string, 1: int, 2: string} jeton, id, email */
function newClient(string $tag, string $first, string $last): array
{
    global $stamp;
    $email = "admin-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'password' => 'admin-test-1234', 'password_confirmation' => 'admin-test-1234']);
    $r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email), 'device' => 'test-admin']);
    return [(string) ($r['body']['data']['token'] ?? ''), (int) substr((string) ($r['body']['data']['user']['id'] ?? 'usr_0'), 4), $email];
}

function agentLogin(): string
{
    global $jar;
    @unlink($jar);
    $page = call('GET', '/pro/connexion', null, null, false, true)['raw'];
    preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
    call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => 'partenaire@example.com', 'password' => 'Partenaire-2026!'], null, false, true);
    preg_match('/name="_csrf" value="([^"]+)"/', call('GET', '/pro', null, null, false, true)['raw'], $m);
    return $m[1] ?? '';
}

$csrf = agentLogin();
check('connexion de l\'agent Axi', $csrf !== '');

section('1. Le client remplace son vrai nom par un pseudonyme : l\'agent le voit');
[$token, $userId] = newClient('pseudo', 'Afiavi', 'Dossou');
$r = call('PATCH', '/v1/me', ['first_name' => 'BigBoss', 'last_name' => 'Du229'], $token);
check('modification acceptée par l\'application', $r['status'] === 200 && ($r['body']['data']['first_name'] ?? '') === 'BigBoss', $r['raw']);
$changes = Database::all('SELECT field, old_value, new_value, source FROM profile_changes WHERE user_id = ? ORDER BY id', [$userId]);
check('ancien et nouveau nom enregistrés dans l\'historique', count($changes) === 2 && $changes[0]['old_value'] === 'Afiavi' && $changes[0]['new_value'] === 'BigBoss' && $changes[1]['new_value'] === 'Du229' && $changes[0]['source'] === 'app', json_encode($changes));
call('PATCH', '/v1/me', ['first_name' => 'BigBoss', 'last_name' => 'Du229'], $token);
check('renvoyer la même valeur n\'ajoute rien', (int) Database::value('SELECT COUNT(*) FROM profile_changes WHERE user_id = ?', [$userId]) === 2);
$list = call('GET', '/pro/clients?changed=identity&q=' . rawurlencode("admin-pseudo-$stamp"), null, null, false, true)['raw'];
check('liste des utilisateurs : filtre « Nom ou étal renommé » et badge « Nom modifié »', str_contains($list, 'BigBoss Du229') && str_contains($list, 'Nom modifié ×2'));
$fiche = call('GET', "/pro/clients/$userId", null, null, false, true)['raw'];
check('fiche : historique avec l\'ancien et le nouveau nom', str_contains($fiche, 'Modifications du profil') && str_contains($fiche, 'Afiavi') && str_contains($fiche, 'BigBoss') && str_contains($fiche, 'La personne (application)'));

section('2. Bloquer un client à tout moment, sans signalement');
check('aucun signalement contre ce compte', (int) Database::value('SELECT COUNT(*) FROM reports WHERE target_user_id = ?', [$userId]) === 0);
$r = call('POST', "/pro/clients/$userId/suspendre", ['_csrf' => $csrf, 'reason' => ''], null, false, true);
check('blocage sans motif refusé', Database::value('SELECT status FROM users WHERE id = ?', [$userId]) === 'active');
call('POST', "/pro/clients/$userId/suspendre", ['_csrf' => $csrf, 'reason' => 'Faux profil ou usurpation d\'identité.'], null, false, true);
check('blocage avec motif : compte bloqué', Database::value('SELECT status FROM users WHERE id = ?', [$userId]) === 'suspended');
$r = call('GET', '/v1/me', null, $token);
check('le client est déconnecté et voit le motif', $r['status'] === 403 && ($r['body']['error']['code'] ?? '') === 'ACCOUNT_SUSPENDED' && str_contains($r['body']['error']['message'] ?? '', 'Faux profil'), $r['raw']);
call('POST', "/pro/clients/$userId/activer", ['_csrf' => $csrf], null, false, true);
check('déblocage', Database::value('SELECT status FROM users WHERE id = ?', [$userId]) === 'active');

section('3. Commerçant : documents à voir et télécharger, étal renommé, blocage');
[$mToken, $mId] = newClient('commercant', 'Koffi', 'Agossou');
$image = "$tmp/photo.png";
$gd = imagecreatetruecolor(320, 200);
imagefill($gd, 0, 0, imagecolorallocate($gd, 22, 101, 52));
imagepng($gd, $image);
$pdf = BASE_PATH . '/tests/fixtures/document.pdf';
$site = (string) Database::value("SELECT id FROM sites WHERE active = 1 AND id <> 'autre' ORDER BY sort_order LIMIT 1");
$aisle = (string) Database::value("SELECT id FROM aisles WHERE active = 1 AND id <> 'autres' ORDER BY sort_order LIMIT 1");
$r = call('POST', '/v1/me/merchant-application', ['phone' => '0196' . substr($stamp, -6), 'id_type' => 'cni', 'id_number' => 'K' . substr($stamp, -5), 'stall_name' => "Chez Koffi $stamp",
    'merchant_type' => 'retail', 'site_id' => $site, 'aisle_id' => $aisle,
    'id_front' => new CURLFile($image, 'image/png'), 'selfie' => new CURLFile($image, 'image/png'), 'stall_photo' => new CURLFile($image, 'image/png'),
    'ifu' => new CURLFile($pdf, 'application/pdf'), 'rccm' => new CURLFile($pdf, 'application/pdf')], $mToken, true);
$appId = (int) substr((string) ($r['body']['data']['application']['id'] ?? 'dem_0'), 4);
check('demande commerçant envoyée', $r['status'] === 201, $r['raw']);
call('POST', "/pro/demandes/$appId/accepter", ['_csrf' => $csrf, 'stall_name' => "Chez Koffi $stamp", 'site_id' => $site, 'aisle_id' => $aisle], null, false, true);
$stallId = (int) Database::value('SELECT id FROM stalls WHERE user_id = ?', [$mId]);
check('demande acceptée : étal créé', $stallId > 0);

$r = call('PATCH', '/v1/merchant/stall', ['name' => 'Supermarché Erevan'], $mToken);
check('le commerçant renomme son étal', $r['status'] === 200, $r['raw']);
$change = Database::one("SELECT * FROM profile_changes WHERE user_id = ? AND field = 'stall_name' ORDER BY id DESC LIMIT 1", [$mId]);
check('nom de l\'étal : avant et après enregistrés', $change !== null && $change['old_value'] === "Chez Koffi $stamp" && $change['new_value'] === 'Supermarché Erevan' && (int) $change['stall_id'] === $stallId);

$fiche = call('GET', "/pro/commercants/$stallId", null, null, false, true)['raw'];
check('fiche commerçant : documents complets (pièce, selfie, IFU, RCCM)', str_contains($fiche, 'Documents du commerçant') && str_contains($fiche, 'Complet') && substr_count($fiche, '>Télécharger<') === 4);
check('fiche commerçant : historique avec l\'étal renommé', str_contains($fiche, 'Supermarché Erevan') && str_contains($fiche, 'Nom de l&#039;étal') || str_contains($fiche, "Nom de l'étal"));
preg_match_all('#href="([^"]*/pro/fichier\?[^"]*dl=1[^"]*)"#', $fiche, $dl);
$ifuLink = null;
foreach ($dl[1] as $href) {
    if (str_contains($href, 'n=IFU')) {
        $href = html_entity_decode($href);
        $ifuLink = substr($href, (int) strpos($href, '/pro/fichier'));
    }
}
$file = $ifuLink !== null ? call('GET', $ifuLink, null, null, false, true) : ['status' => 0, 'headers' => [], 'raw' => ''];
check('télécharger l\'IFU : PDF en pièce jointe, nommé avec le commerçant', $file['status'] === 200 && str_starts_with($file['raw'], '%PDF-')
    && str_starts_with($file['headers']['content-disposition'] ?? '', 'attachment') && str_contains($file['headers']['content-disposition'] ?? '', 'IFU-Koffi-Agossou.pdf'), json_encode($file['headers']));

call('POST', "/pro/commercants/$stallId/suspendre", ['_csrf' => $csrf, 'reason' => 'abc'], null, false, true);
check('blocage du commerçant sans vrai motif refusé', Database::value('SELECT status FROM users WHERE id = ?', [$mId]) === 'active');
call('POST', "/pro/commercants/$stallId/suspendre", ['_csrf' => $csrf, 'reason' => 'Nom d\'étal usurpé (enseigne d\'un autre).'], null, false, true);
check('commerçant bloqué à tout moment : compte et étal suspendus', Database::value('SELECT status FROM users WHERE id = ?', [$mId]) === 'suspended' && Database::value('SELECT status FROM stalls WHERE id = ?', [$stallId]) === 'suspended');
$r = call('GET', '/v1/me', null, $mToken);
check('le commerçant voit le motif', ($r['body']['error']['code'] ?? '') === 'ACCOUNT_SUSPENDED' && str_contains($r['body']['error']['message'] ?? '', 'usurpé'), $r['raw']);
check('fiche commerçant : motif affiché et bouton de déblocage', str_contains(call('GET', "/pro/commercants/$stallId", null, null, false, true)['raw'], 'Débloquer le commerçant'));
call('POST', "/pro/commercants/$stallId/activer", ['_csrf' => $csrf], null, false, true);
check('déblocage : compte et étal actifs', Database::value('SELECT status FROM users WHERE id = ?', [$mId]) === 'active' && Database::value('SELECT status FROM stalls WHERE id = ?', [$stallId]) === 'active');

section('4. Commerçant sans demande : documents signalés comme manquants');
$old = Database::one('SELECT s.id, s.user_id FROM stalls s WHERE NOT EXISTS (SELECT 1 FROM merchant_applications a WHERE a.user_id = s.user_id) LIMIT 1');
if ($old !== null) {
    check('fiche : « aucune demande » clairement indiqué', str_contains(call('GET', '/pro/commercants/' . $old['id'], null, null, false, true)['raw'], 'n\'a envoyé ni pièce') || str_contains(call('GET', '/pro/commercants/' . $old['id'], null, null, false, true)['raw'], 'n&#039;a envoyé ni pièce'));
} else {
    echo "  (aucun étal sans demande dans cette base : vérification sautée)\n";
}
$oldApp = Database::one('SELECT a.user_id, s.id AS stall_id FROM merchant_applications a JOIN stalls s ON s.user_id = a.user_id WHERE a.ifu_path IS NULL LIMIT 1');
if ($oldApp !== null) {
    check('demande antérieure à l\'IFU : « Pas encore envoyé »', str_contains(call('GET', '/pro/commercants/' . $oldApp['stall_id'], null, null, false, true)['raw'], 'Pas encore envoyé'));
}

section('5. Accès réservé aux agents');
@unlink($jar);
check('sans session d\'agent : fiche inaccessible', !str_contains(call('GET', "/pro/commercants/$stallId", null, null, false, true)['raw'], 'Documents du commerçant'));
if ($ifuLink !== null) {
    check('sans session d\'agent : téléchargement impossible', !str_starts_with(call('GET', $ifuLink, null, null, false, true)['raw'], '%PDF-'));
}

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo "\n$checks vérifications, " . ($failures === 0 ? 'toutes réussies' : "$failures en échec") . "\n";
exit($failures === 0 ? 0 : 1);
