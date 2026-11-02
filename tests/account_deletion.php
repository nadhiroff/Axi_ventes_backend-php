<?php
declare(strict_types=1);

// Suppression de compte : demande (mot de passe + raison) en attente, retrait par la personne, annulation ou
// effacement définitif par un agent Axi (informations et fichiers), refus tant que de l'argent est en jeu.
// Usage : php tests/account_deletion.php [URL]   (défaut http://localhost:8000, env local, comptes de test de reset.sql)

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Services\Payment\FakeGateway;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$stamp = (string) time();
$failures = 0;
$checks = 0;
$tmp = sys_get_temp_dir() . "/axi-suppression-$stamp";
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
    $h = $web ? [] : ['Accept: application/json', 'X-Axi-Test-Gateway: fake'];
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
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $raw = (string) curl_exec($ch);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
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
function newClient(string $tag): array
{
    global $stamp;
    $email = "supp-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => ucfirst($tag), 'last_name' => 'Test', 'email' => $email, 'password' => 'suppression-1234', 'password_confirmation' => 'suppression-1234']);
    $r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email), 'device' => 'test-suppression']);
    return [(string) ($r['body']['data']['token'] ?? ''), (int) substr((string) ($r['body']['data']['user']['id'] ?? 'usr_0'), 4), $email];
}

$csrf = '';
function agentLogin(): void
{
    global $jar, $csrf;
    @unlink($jar);
    preg_match('/name="_csrf" value="([^"]+)"/', call('GET', '/pro/connexion', null, null, false, true)['raw'], $m);
    call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => 'partenaire@example.com', 'password' => 'Partenaire-2026!'], null, false, true);
    preg_match('/name="_csrf" value="([^"]+)"/', call('GET', '/pro', null, null, false, true)['raw'], $m);
    $csrf = $m[1] ?? '';
}

function requestId(int $userId): int
{
    return (int) Database::value("SELECT id FROM account_deletion_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
}

$image = "$tmp/photo.png";
$gd = imagecreatetruecolor(320, 240);
imagefill($gd, 0, 0, imagecolorallocate($gd, random_int(0, 255), random_int(0, 255), (int) substr($stamp, -2)));
imagepng($gd, $image);
// Photo de profil : une image à elle seule (les photos sont rangées par contenu, un même fichier peut servir ailleurs)
$avatarImage = "$tmp/avatar.png";
$gd2 = imagecreatetruecolor(200, 200);
imagefill($gd2, 0, 0, imagecolorallocate($gd2, 10, 200, (int) substr($stamp, -2)));
imagestring($gd2, 5, 20, 90, "avatar $stamp", imagecolorallocate($gd2, 255, 255, 255));
imagepng($gd2, $avatarImage);
$pdf = BASE_PATH . '/tests/fixtures/document.pdf';
$site = (string) Database::value("SELECT id FROM sites WHERE active = 1 AND id <> 'autre' ORDER BY sort_order LIMIT 1");
$aisle = (string) Database::value("SELECT id FROM aisles WHERE active = 1 AND id <> 'autres' ORDER BY sort_order LIMIT 1");

/** Commerçant validé par un agent ; @return array{0: string, 1: int, 2: int} jeton, id, étal */
function newMerchant(string $tag): array
{
    global $image, $pdf, $site, $aisle, $stamp, $csrf;
    [$token, $id] = newClient($tag);
    $r = call('POST', '/v1/me/merchant-application', ['phone' => '0194' . substr($stamp, -4) . random_int(10, 99), 'id_type' => 'cni', 'id_number' => 'S' . random_int(10000, 99999), 'stall_name' => "Étal $tag $stamp",
        'merchant_type' => 'retail', 'site_id' => $site, 'aisle_id' => $aisle, 'id_front' => new CURLFile($image, 'image/png'), 'selfie' => new CURLFile($image, 'image/png'),
        'stall_photo' => new CURLFile($image, 'image/png'), 'ifu' => new CURLFile($pdf, 'application/pdf'), 'rccm' => new CURLFile($pdf, 'application/pdf')], $token, true);
    $appId = (int) substr((string) ($r['body']['data']['application']['id'] ?? 'dem_0'), 4);
    agentLogin();
    call('POST', "/pro/demandes/$appId/accepter", ['_csrf' => $csrf, 'stall_name' => "Étal $tag $stamp", 'site_id' => $site, 'aisle_id' => $aisle], null, false, true);
    return [$token, $id, (int) Database::value('SELECT id FROM stalls WHERE user_id = ?', [$id])];
}

try {
    section('1. Demande : mot de passe ET raison, en attente, annulable par la personne');
    [$token, $userId] = newClient('afi');
    $r = call('POST', '/v1/me/avatar', ['photo' => new CURLFile($avatarImage, 'image/png')], $token, true);
    $avatar = (string) Database::value('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
    check('préparation : compte avec photo de profil', $avatar !== '' && is_dir(BASE_PATH . '/public/' . $avatar), $r['raw']);
    $r = call('POST', '/v1/me/delete', ['password' => 'suppression-1234'], $token);
    check('sans raison : refusée', $r['status'] === 422 && isset($r['body']['error']['fields']['reason']), $r['raw']);
    $r = call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'trop'], $token);
    check('raison trop courte : refusée', $r['status'] === 422, $r['raw']);
    $r = call('POST', '/v1/me/delete', ['password' => 'mauvais-mot', 'reason' => 'Je n\'utilise plus l\'application.'], $token);
    check('mauvais mot de passe : refusée', $r['status'] === 422 && isset($r['body']['error']['fields']['password']), $r['raw']);
    $r = call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'Je n\'utilise plus l\'application.'], $token);
    check('demande envoyée : en attente, message « envoyée à l\'équipe Axi »', $r['status'] === 201 && ($r['body']['data']['status'] ?? '') === 'pending' && str_contains($r['body']['data']['message'] ?? '', 'équipe Axi'), $r['raw']);
    check('le compte reste utilisable en attendant', call('GET', '/v1/me', null, $token)['status'] === 200);
    check('l\'application retrouve la demande en attente', (call('GET', '/v1/me/deletion-request', null, $token)['body']['data']['request']['reason'] ?? '') === 'Je n\'utilise plus l\'application.');
    check('deuxième demande pendant l\'attente : refusée (409)', call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'Encore une fois, merci.'], $token)['status'] === 409);
    check('les agents Axi sont alertés', Database::value("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id JOIN organizations o ON o.id = u.organization_id WHERE o.type = 'axi' AND n.title = 'Demande de suppression de compte' AND n.body LIKE ?", ['%' . "supp-afi-$stamp" . '%']) > 0);
    $r = call('DELETE', '/v1/me/deletion-request', null, $token);
    check('la personne retire sa demande', $r['status'] === 200 && call('GET', '/v1/me/deletion-request', null, $token)['body']['data']['request'] === null, $r['raw']);

    section('2. L\'agent annule une demande : le compte est gardé, la personne prévenue');
    call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'Je veux partir, merci pour tout.'], $token);
    agentLogin();
    $page = call('GET', '/pro/suppressions', null, null, false, true)['raw'];
    check('la demande apparaît dans « Suppressions de compte » avec la raison', str_contains($page, "supp-afi-$stamp") && str_contains($page, 'Je veux partir'));
    $rid = requestId($userId);
    call('POST', "/pro/suppressions/$rid/annuler", ['_csrf' => $csrf, 'note' => ''], null, false, true);
    check('annulation sans message : refusée', requestId($userId) === $rid);
    call('POST', "/pro/suppressions/$rid/annuler", ['_csrf' => $csrf, 'note' => 'Nous gardons votre compte : écrivez-nous si besoin.'], null, false, true);
    check('demande annulée, compte actif', Database::value('SELECT status FROM account_deletion_requests WHERE id = ?', [$rid]) === 'cancelled' && Database::value('SELECT status FROM users WHERE id = ?', [$userId]) === 'active');
    check('la personne reçoit le message de l\'agent', Database::value("SELECT 1 FROM notifications WHERE user_id = ? AND title = 'Demande de suppression annulée' AND body LIKE '%écrivez-nous%'", [$userId]) !== null);

    section('3. Effacement définitif d\'un client sans commande : plus aucune trace');
    [$mToken, $mId, $stallId] = newMerchant('vendeur');
    call('POST', "/v1/stalls/stl_$stallId/follow", [], $token);
    $conv = (string) (call('POST', "/v1/stalls/stl_$stallId/conversation", [], $token)['body']['data']['conversation']['id'] ?? '');
    call('POST', "/v1/conversations/$conv/messages", ['body' => 'Message qui doit disparaître'], $token);
    $productId = Database::insert("INSERT INTO products (stall_id, name, price_amount, in_stock, status, created_at) VALUES (?, ?, 1500, 1, 'active', ?)", [$stallId, "Produit supp $stamp", Clock::now()]);
    call('POST', "/v1/products/prd_$productId/favorite", [], $token);
    call('PATCH', '/v1/me', ['first_name' => 'Afiavi'], $token);
    call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'Je ne veux plus de compte Axi.'], $token);
    $rid = requestId($userId);
    agentLogin();
    call('POST', "/pro/suppressions/$rid/effacer", ['_csrf' => $csrf], null, false, true);
    check('la ligne du compte est supprimée (aucune commande à garder)', Database::value('SELECT 1 FROM users WHERE id = ?', [$userId]) === null);
    $left = [];
    foreach (['user_settings', 'favorites', 'stall_follows', 'notifications', 'auth_tokens', 'login_events', 'profile_changes', 'merchant_clients'] as $t) {
        if ((int) Database::value("SELECT COUNT(*) FROM $t WHERE user_id = ?", [$userId]) > 0) {
            $left[] = $t;
        }
    }
    check('aucune donnée restante (réglages, favoris, suivis, notifications, connexions, historique, fichiers clients)', $left === [], implode(', ', $left));
    check('discussion et messages effacés', Database::value('SELECT 1 FROM conversations WHERE id = ?', [(int) substr($conv, 5)]) === null && Database::value('SELECT 1 FROM messages WHERE body = ?', ['Message qui doit disparaître']) === null);
    check('photo de profil effacée du disque', !is_dir(BASE_PATH . '/public/' . $avatar));
    check('connexion impossible', call('POST', '/v1/auth/login', ['email' => "supp-afi-$stamp@example.com", 'password' => 'suppression-1234'])['status'] >= 400);
    $req = Database::one('SELECT * FROM account_deletion_requests WHERE id = ?', [$rid]);
    check('la demande garde seulement qui et quand (raison effacée)', $req['status'] === 'erased' && $req['reason'] === null && $req['decided_by'] !== null);
    check('page admin : « Compte effacé », sans nom ni raison', !str_contains(call('GET', '/pro/suppressions?status=erased', null, null, false, true)['raw'], "supp-afi-$stamp"));

    section('4. Commerçant sans vente : étal, pièces d\'identité, IFU et RCCM effacés du disque');
    [$m2Token, $m2Id, $stall2] = newMerchant('commercant');
    $files = Database::one('SELECT * FROM merchant_applications WHERE user_id = ?', [$m2Id]);
    $paths = array_filter([$files['id_front_path'], $files['selfie_path'], $files['ifu_path'], $files['rccm_path']]);
    check('préparation : documents présents sur le disque', count($paths) === 4 && array_filter($paths, static fn ($p) => !is_file(BASE_PATH . '/storage/' . $p)) === []);
    call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'J\'arrête mon commerce.'], $m2Token);
    $rid = requestId($m2Id);
    agentLogin();
    call('POST', "/pro/suppressions/$rid/effacer", ['_csrf' => $csrf], null, false, true);
    check('pièce d\'identité, selfie, IFU et RCCM effacés du disque', array_filter($paths, static fn ($p) => is_file(BASE_PATH . '/storage/' . $p)) === []);
    check('étal et demande commerçant supprimés', Database::value('SELECT 1 FROM stalls WHERE id = ?', [$stall2]) === null && Database::value('SELECT 1 FROM merchant_applications WHERE user_id = ?', [$m2Id]) === null);
    check('compte supprimé', Database::value('SELECT 1 FROM users WHERE id = ?', [$m2Id]) === null);

    section('5. Client avec des commandes passées : compte vidé, commandes gardées sans nom');
    [$cToken, $cId] = newClient('acheteur');
    $order = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$productId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Akpakpa, près du marché'], 'payment' => ['operator' => 'mtn', 'phone' => '+2290197000003']], $cToken, false);
    $orderId = (int) substr((string) ($order['body']['data']['id'] ?? 'cmd_0'), 4);
    $ref = (string) Database::value('SELECT reference FROM payments WHERE order_id = ?', [$orderId]);
    touch(FakeGateway::marker("pending-$ref"));
    call('POST', "/v1/orders/cmd_$orderId/cancel", [], $cToken);
    @unlink(FakeGateway::marker("pending-$ref"));
    check('préparation : commande annulée dans l\'historique', Database::value('SELECT status FROM orders WHERE id = ?', [$orderId]) === 'cancelled', $order['raw']);
    call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'Je préfère acheter au marché.'], $cToken);
    $rid = requestId($cId);
    agentLogin();
    call('POST', "/pro/suppressions/$rid/effacer", ['_csrf' => $csrf], null, false, true);
    $u = Database::one('SELECT * FROM users WHERE id = ?', [$cId]);
    check('compte vidé : « Compte supprimé », sans email, téléphone ni présentation', $u !== null && $u['status'] === 'deleted' && $u['name'] === 'Compte supprimé' && str_ends_with($u['email'], '@deleted.invalid') && $u['phone'] === null);
    check('commande gardée pour la comptabilité, sans adresse ni numéro', Database::value('SELECT reception_detail FROM orders WHERE id = ?', [$orderId]) === 'Effacé' && Database::value('SELECT payment_phone FROM orders WHERE id = ?', [$orderId]) === null);

    section('6. Argent encore en jeu : effacement refusé tant que ce n\'est pas réglé');
    [$pToken, $pId] = newClient('payeur');
    $order = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$productId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Cadjehoun'], 'payment' => ['operator' => 'mtn', 'phone' => '+2290197000004']], $pToken);
    $paidOrder = (int) substr((string) ($order['body']['data']['id'] ?? 'cmd_0'), 4);
    call('POST', "/v1/orders/cmd_$paidOrder/verify-payment", [], $pToken);
    check('préparation : commande payée, pas encore reçue', Database::value('SELECT status FROM orders WHERE id = ?', [$paidOrder]) === 'paid');
    call('POST', '/v1/me/delete', ['password' => 'suppression-1234', 'reason' => 'Je veux supprimer mon compte.'], $pToken);
    $rid = requestId($pId);
    agentLogin();
    check('page admin : « À régler d\'abord » au lieu du bouton d\'effacement', str_contains(call('GET', '/pro/suppressions', null, null, false, true)['raw'], 'À régler d'));
    call('POST', "/pro/suppressions/$rid/effacer", ['_csrf' => $csrf], null, false, true);
    check('effacement refusé : compte et commande intacts, demande toujours en attente', Database::value('SELECT status FROM users WHERE id = ?', [$pId]) === 'active' && requestId($pId) === $rid);
    Database::run("UPDATE account_deletion_requests SET status = 'withdrawn' WHERE id = ?", [$rid]);
} finally {
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
}

echo "\n$checks vérifications, " . ($failures === 0 ? 'toutes réussies' : "$failures en échec") . "\n";
exit($failures === 0 ? 0 : 1);
