<?php
declare(strict_types=1);

// Blocage entre utilisateurs : un client et un commerçant ne se voient plus nulle part et ne peuvent plus
// s'écrire ni se commander quoi que ce soit, dans les deux sens ; débloquer rétablit tout.
// Usage : php tests/user_blocks.php [URL]   (défaut http://localhost:8000, env local, comptes de test de reset.sql)

use Axi\Core\Clock;
use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$stamp = (string) time();
$failures = 0;
$checks = 0;
$tmp = sys_get_temp_dir() . "/axi-blocage-$stamp";
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
function newClient(string $tag, string $first): array
{
    global $stamp;
    $email = "bloc-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => $first, 'last_name' => 'Test', 'email' => $email, 'password' => 'blocage-1234', 'password_confirmation' => 'blocage-1234']);
    $r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email), 'device' => 'test-blocage']);
    return [(string) ($r['body']['data']['token'] ?? ''), (int) substr((string) ($r['body']['data']['user']['id'] ?? 'usr_0'), 4), $email];
}

$ids = static fn (array $list, string $key = 'id'): array => array_column($list, $key);

// --- Préparation : un commerçant (demande acceptée par un agent) avec un produit, et une cliente ----------------
[$mToken, $mId, $mEmail] = newClient('commercant', 'Kossi');
[$cToken, $cId, $cEmail] = newClient('cliente', 'Afi');
$image = "$tmp/photo.png";
$gd = imagecreatetruecolor(300, 200);
imagefill($gd, 0, 0, imagecolorallocate($gd, 120, 60, 20));
imagepng($gd, $image);
$pdf = BASE_PATH . '/tests/fixtures/document.pdf';
$site = (string) Database::value("SELECT id FROM sites WHERE active = 1 AND id <> 'autre' ORDER BY sort_order LIMIT 1");
$aisle = (string) Database::value("SELECT id FROM aisles WHERE active = 1 AND id <> 'autres' ORDER BY sort_order LIMIT 1");
$stallName = "Étal Kossi bloc $stamp";
$r = call('POST', '/v1/me/merchant-application', ['phone' => '0195' . substr($stamp, -6), 'id_type' => 'cni', 'id_number' => 'B' . substr($stamp, -5), 'stall_name' => $stallName,
    'merchant_type' => 'retail', 'site_id' => $site, 'aisle_id' => $aisle, 'id_front' => new CURLFile($image, 'image/png'), 'selfie' => new CURLFile($image, 'image/png'),
    'stall_photo' => new CURLFile($image, 'image/png'), 'ifu' => new CURLFile($pdf, 'application/pdf'), 'rccm' => new CURLFile($pdf, 'application/pdf')], $mToken, true);
$appId = (int) substr((string) ($r['body']['data']['application']['id'] ?? 'dem_0'), 4);
$page = call('GET', '/pro/connexion', null, null, false, true)['raw'];
preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => 'partenaire@example.com', 'password' => 'Partenaire-2026!'], null, false, true);
preg_match('/name="_csrf" value="([^"]+)"/', call('GET', '/pro', null, null, false, true)['raw'], $m);
call('POST', "/pro/demandes/$appId/accepter", ['_csrf' => $m[1] ?? '', 'stall_name' => $stallName, 'site_id' => $site, 'aisle_id' => $aisle], null, false, true);
$stallId = (int) Database::value('SELECT id FROM stalls WHERE user_id = ?', [$mId]);
$productName = "Pagne bloc $stamp";
$productId = Database::insert(
    "INSERT INTO products (stall_id, name, price_amount, in_stock, status, created_at) VALUES (?, ?, 2500, 1, 'active', ?)",
    [$stallId, $productName, Clock::now()],
);
check('préparation : commerçant validé, étal et produit en vente', $stallId > 0 && $productId > 0 && $cToken !== '');

call('POST', "/v1/stalls/stl_$stallId/follow", [], $cToken);
$conv = (string) (call('POST', "/v1/stalls/stl_$stallId/conversation", [], $cToken)['body']['data']['conversation']['id'] ?? '');
if ($conv === '') {
    foreach (['/v1/conversations/stall/stl_' . $stallId, "/v1/stalls/stl_$stallId/conversations"] as $try) {
        $conv = (string) (call('POST', $try, [], $cToken)['body']['data']['conversation']['id'] ?? '');
        if ($conv !== '') {
            break;
        }
    }
}
call('POST', "/v1/conversations/$conv/messages", ['body' => 'Bonjour, vous avez encore des pagnes ?'], $cToken);
$merchantUserId = 'usr_' . $mId;
check('avant le blocage : la cliente (abonnée) est dans « Mes clients »', in_array("usr_$cId", array_column(call('GET', '/v1/merchant/clients', null, $mToken)['body']['data']['clients'] ?? [], 'user_id'), true));
check('avant le blocage : discussion visible des deux côtés', in_array($conv, $ids(call('GET', '/v1/conversations', null, $cToken)['body']['data']['conversations'] ?? []), true)
    && in_array($conv, $ids(call('GET', '/v1/conversations', null, $mToken)['body']['data']['conversations'] ?? []), true), "conversation « $conv »");

section('1. La cliente bloque le commerçant');
$r = call('POST', "/v1/users/$merchantUserId/block", [], $cToken);
check('blocage accepté', $r['status'] === 200 && ($r['body']['data']['blocked'] ?? false) === true, $r['raw']);
check('elle ne suit plus l\'étal', Database::value('SELECT 1 FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$cId, $stallId]) === null);
check('cliente : la discussion disparaît de sa liste', !in_array($conv, $ids(call('GET', '/v1/conversations', null, $cToken)['body']['data']['conversations'] ?? []), true));
check('cliente : la discussion ne s\'ouvre plus', call('GET', "/v1/conversations/$conv/messages", null, $cToken)['status'] === 404);
check('cliente : impossible d\'écrire', call('POST', "/v1/conversations/$conv/messages", ['body' => 'test'], $cToken)['status'] === 404);
check('cliente : l\'étal est introuvable', call('GET', "/v1/stalls/stl_$stallId", null, $cToken)['status'] === 404);
check('cliente : le produit est introuvable', call('GET', "/v1/products/prd_$productId", null, $cToken)['status'] === 404);
$search = call('GET', '/v1/search?q=' . rawurlencode("bloc $stamp"), null, $cToken)['body']['data'] ?? [];
check('cliente : ni l\'étal ni le produit dans la recherche', !in_array("stl_$stallId", $ids($search['stalls'] ?? []), true) && !in_array("prd_$productId", $ids($search['products'] ?? []), true), json_encode($search));
check('cliente : impossible de suivre l\'étal', call('POST', "/v1/stalls/stl_$stallId/follow", [], $cToken)['status'] === 404);
$order = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$productId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Test blocage'], 'payment' => ['operator' => 'mtn', 'phone' => '+2290197000002']], $cToken);
check('cliente : impossible de commander ses produits', $order['status'] === 422 && ($order['body']['error']['code'] ?? '') === 'PRODUCT_UNAVAILABLE', $order['raw']);
check('cliente : son profil est introuvable', call('GET', "/v1/users/$merchantUserId/profile", null, $cToken)['status'] === 404);
$visitor = call('GET', "/v1/stalls/stl_$stallId");
check('un visiteur, lui, voit toujours l\'étal (le blocage ne concerne que les deux)', $visitor['status'] === 200, $visitor['raw']);

check('commerçant : la discussion disparaît aussi de sa liste', !in_array($conv, $ids(call('GET', '/v1/conversations', null, $mToken)['body']['data']['conversations'] ?? []), true));
check('commerçant : impossible de lui écrire', call('POST', "/v1/conversations/$conv/messages", ['body' => 'Vous êtes là ?'], $mToken)['status'] === 404);
check('commerçant : son profil à elle est introuvable', call('GET', "/v1/users/usr_$cId/profile", null, $mToken)['status'] === 404);
$clients = call('GET', '/v1/merchant/clients', null, $mToken)['body']['data']['clients'] ?? [];
check('commerçant : elle n\'apparaît plus dans « Mes clients »', !in_array("usr_$cId", array_column($clients, 'user_id'), true) && !in_array($cEmail, array_column($clients, 'email'), true), json_encode(array_column($clients, 'display_name')));
$lookup = call('GET', '/v1/merchant/clients/lookup?contact=' . rawurlencode($cEmail), null, $mToken)['body']['data'] ?? [];
check('commerçant : la retrouver par son email ne donne rien', ($lookup['found'] ?? true) === false, json_encode($lookup));
$add = call('POST', '/v1/merchant/clients', ['contact' => $cEmail, 'display_name' => 'Afi'], $mToken);
check('commerçant : impossible de l\'ajouter à la main', $add['status'] === 422 && ($add['body']['error']['code'] ?? '') === 'CONTACT_UNAVAILABLE', $add['raw']);
$badges = call('GET', '/v1/me/badges', null, $mToken)['body']['data'] ?? [];
check('commerçant : son message ne compte plus dans les pastilles', (int) ($badges['unread_as_merchant'] ?? 1) === 0, json_encode($badges));

$list = call('GET', '/v1/me/blocked-users', null, $cToken)['body']['data']['users'] ?? [];
check('cliente : le commerçant est dans « Utilisateurs bloqués » (avec son étal)', count($list) === 1 && $list[0]['id'] === $merchantUserId && $list[0]['stall_name'] === $stallName, json_encode($list));
check('commerçant : il ne sait pas qu\'il est bloqué (sa liste est vide)', (call('GET', '/v1/me/blocked-users', null, $mToken)['body']['data']['users'] ?? [1]) === []);

section('2. Débloquer rétablit tout');
$r = call('DELETE', "/v1/users/$merchantUserId/block", null, $cToken);
check('déblocage accepté', $r['status'] === 200 && ($r['body']['data']['blocked'] ?? true) === false, $r['raw']);
check('la discussion revient des deux côtés, avec ses messages', in_array($conv, $ids(call('GET', '/v1/conversations', null, $cToken)['body']['data']['conversations'] ?? []), true)
    && count(call('GET', "/v1/conversations/$conv/messages", null, $mToken)['body']['data']['messages'] ?? []) >= 1);
check('l\'étal et le produit sont de nouveau visibles', call('GET', "/v1/stalls/stl_$stallId", null, $cToken)['status'] === 200 && call('GET', "/v1/products/prd_$productId", null, $cToken)['status'] === 200);

section('3. Dans l\'autre sens : le commerçant bloque la cliente');
call('POST', "/v1/users/usr_$cId/block", [], $mToken);
check('cliente : l\'étal du commerçant qui l\'a bloquée est introuvable', call('GET', "/v1/stalls/stl_$stallId", null, $cToken)['status'] === 404);
check('cliente : elle ne peut plus lui écrire', call('POST', "/v1/conversations/$conv/messages", ['body' => 'Bonjour ?'], $cToken)['status'] === 404);
check('cliente : elle ne voit pas son profil', call('GET', "/v1/users/$merchantUserId/profile", null, $cToken)['status'] === 404);
check('commerçant : la cliente est dans sa liste de bloqués', in_array("usr_$cId", $ids(call('GET', '/v1/me/blocked-users', null, $mToken)['body']['data']['users'] ?? []), true));
call('DELETE', "/v1/users/usr_$cId/block", null, $mToken);
check('débloquée : elle revoit l\'étal', call('GET', "/v1/stalls/stl_$stallId", null, $cToken)['status'] === 200);

section('4. Cas limites');
check('se bloquer soi-même : refusé', call('POST', "/v1/users/usr_$cId/block", [], $cToken)['status'] === 422);
check('bloquer un compte qui n\'existe pas : 404', call('POST', '/v1/users/usr_999999999/block', [], $cToken)['status'] === 404);
call('POST', "/v1/users/$merchantUserId/block", [], $cToken);
check('bloquer deux fois : sans erreur, une seule ligne', call('POST', "/v1/users/$merchantUserId/block", [], $cToken)['status'] === 200
    && (int) Database::value('SELECT COUNT(*) FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$cId, $mId]) === 1);
call('DELETE', "/v1/users/$merchantUserId/block", null, $cToken);

Database::run('DELETE FROM products WHERE id = ?', [$productId]);
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo "\n$checks vérifications, " . ($failures === 0 ? 'toutes réussies' : "$failures en échec") . "\n";
exit($failures === 0 ? 0 : 1);
