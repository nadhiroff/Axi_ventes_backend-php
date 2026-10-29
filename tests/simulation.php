<?php
declare(strict_types=1);

// Simulation complète des parcours Axi contre l'API en marche, avec vérification des enregistrements en base.
// Usage : php tests/simulation.php [URL de l'API]   (défaut http://localhost:8000)
// Prérequis : APP_ENV=local, payment_provider 'fake', données de démonstration (php database/seed.php).
// Chaque lancement crée de nouveaux comptes (adresses @example.com, aucun email réel).

use Axi\Core\Database;
use Axi\Services\Payment\FakePaymentProvider;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$stamp = (string) time();
$failures = 0;
$checks = 0;

function section(string $title): void
{
    echo "\n== $title\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  OK      $label\n";
    } else {
        $failures++;
        echo "  ÉCHEC   $label" . ($detail !== '' ? "\n          " . mb_substr($detail, 0, 400) : '') . "\n";
    }
}

/** @return array{status: int, body: array, headers: array<string, string>, raw: string} */
function call(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = [], bool $multipart = false): array
{
    global $api;
    $ch = curl_init($api . $path);
    $h = ['Accept: application/json'];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    if ($body !== null) {
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return ['status' => $status, 'body' => json_decode($raw, true) ?? [], 'headers' => $responseHeaders, 'raw' => $raw];
}

/** Envoie plusieurs requêtes en même temps (double appui, réseau qui renvoie) */
function parallel(int $n, string $path, array $body, string $token, array $headers = []): array
{
    global $api;
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init($api . $path);
        $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    $results = [];
    foreach ($handles as $ch) {
        $results[] = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => json_decode((string) curl_multi_getcontent($ch), true) ?? []];
        curl_multi_remove_handle($mh, $ch);
    }
    return $results;
}

function lastCode(string $email, string $purpose): ?string
{
    $lines = @file(BASE_PATH . '/storage/logs/codes.log', FILE_IGNORE_NEW_LINES) ?: [];
    foreach (array_reverse($lines) as $line) {
        if (preg_match('/ ' . preg_quote($email, '/') . ' ' . $purpose . ' (\d{6})$/', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

function login(string $email, string $password = 'axi-demo-2026'): string
{
    $r = call('POST', '/v1/auth/login', ['email' => $email, 'password' => $password, 'device' => 'simulation']);
    return (string) ($r['body']['data']['token'] ?? '');
}

/** Crée un compte client vérifié et renvoie [jeton, id numérique] */
function newClient(string $tag, string $phone): array
{
    global $stamp;
    $email = "sim-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => 'Sim', 'last_name' => ucfirst($tag), 'email' => $email, 'password' => 'simulation-123', 'phone' => $phone]);
    $r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email, 'verify_email'), 'device' => 'simulation']);
    return [(string) ($r['body']['data']['token'] ?? ''), (int) substr((string) ($r['body']['data']['user']['id'] ?? 'usr_0'), 4)];
}

function debits(string $reference): int
{
    $log = (string) @file_get_contents(BASE_PATH . '/storage/logs/payments.log');
    return substr_count($log, "DEMANDE DE DÉBIT $reference ");
}

$phone = static fn (int $n): string => '+22901' . substr($stamp, -6) . sprintf('%02d', $n);

if (Database::value('SELECT id FROM users WHERE email = ?', ['agent@example.com']) === null) {
    exit("Lancez d'abord : php database/seed.php\n");
}
echo "Simulation Axi contre $api\n";

// ---------------------------------------------------------------------------
section('1. Inscription, confirmation, connexion, profil');
$email = "sim-auth-$stamp@example.com";
$r = call('POST', '/v1/auth/register', ['first_name' => ' Afi ', 'last_name' => 'Houngbo', 'email' => strtoupper($email), 'password' => 'motdepasse-1', 'phone' => '01' . substr($phone(1), 6)]);
check('inscription : 201 et compte client non confirmé', $r['status'] === 201 && $r['body']['data']['user']['role'] === 'client' && $r['body']['data']['user']['email_verified'] === false, $r['raw']);
$user = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
check('base : email en minuscules, prénom nettoyé, name = prénom + nom', $user !== null && $user['first_name'] === 'Afi' && $user['name'] === 'Afi Houngbo', json_encode($user));
check('base : téléphone normalisé en +229 01…', $user !== null && $user['phone'] === $phone(1), (string) ($user['phone'] ?? ''));
check('base : mot de passe haché (bcrypt), jamais en clair', $user !== null && str_starts_with($user['password_hash'], '$2y$') && password_verify('motdepasse-1', $user['password_hash']));
$code = lastCode($email, 'verify_email');
$codeRow = Database::one('SELECT * FROM email_codes WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]);
check('base : code stocké en HMAC (64 car.), pas en clair', $codeRow !== null && strlen($codeRow['code_hash']) === 64 && $codeRow['code_hash'] !== $code);
check('base : code valable 15 minutes', $codeRow !== null && abs(strtotime($codeRow['expires_at']) - strtotime($codeRow['created_at']) - 900) <= 2);
$r = call('POST', '/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'email' => $email, 'password' => 'motdepasse-1', 'phone' => $phone(2)]);
check('doublon email : 409 EMAIL_TAKEN', $r['status'] === 409 && $r['body']['error']['code'] === 'EMAIL_TAKEN');
$r = call('POST', '/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'email' => "autre-$email", 'password' => 'motdepasse-1', 'phone' => $phone(1)]);
check('doublon téléphone : 409 PHONE_TAKEN', $r['status'] === 409 && $r['body']['error']['code'] === 'PHONE_TAKEN');
$r = call('POST', '/v1/auth/register', ['first_name' => '', 'last_name' => 'Y', 'email' => 'pas-un-email', 'password' => 'court', 'phone' => '12']);
check('saisie invalide : 422 VALIDATION_ERROR avec 4 champs', $r['status'] === 422 && count($r['body']['error']['fields'] ?? []) === 4, $r['raw']);
$r = call('POST', '/v1/auth/login', ['email' => $email, 'password' => 'motdepasse-1']);
check('connexion avant confirmation : 403 EMAIL_NOT_VERIFIED', $r['status'] === 403 && $r['body']['error']['code'] === 'EMAIL_NOT_VERIFIED');
$r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => $code === '000000' ? '111111' : '000000']);
check('mauvais code : 422 CODE_INVALID, 4 essais restants', $r['status'] === 422 && ($r['body']['error']['remaining_attempts'] ?? null) === 4, $r['raw']);
$r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => $code, 'device' => 'simulation']);
$token = (string) ($r['body']['data']['token'] ?? '');
check('bon code : jeton de 64 caractères', $r['status'] === 200 && preg_match('/^[a-f0-9]{64}$/', $token) === 1, $r['raw']);
$tokenRow = Database::one('SELECT * FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
check('base : seul le sha256 du jeton est stocké, valable 90 jours', $tokenRow !== null && Database::value('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ?', [$token]) === 0
    && abs(strtotime($tokenRow['expires_at']) - time() - 90 * 86400) < 60);
check('base : adresse confirmée, code consommé', Database::value('SELECT email_verified_at FROM users WHERE email = ?', [$email]) !== null && Database::value('SELECT used_at FROM email_codes WHERE id = ?', [$codeRow['id']]) !== null);
$r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => $code]);
check('code déjà utilisé : refusé', $r['status'] === 422);
$r = call('PATCH', '/v1/me', ['last_name' => 'Houngbo-Dossou', 'lang' => 'fon'], $token);
check('PATCH /v1/me : nom et langue mis à jour', ($r['body']['data']['name'] ?? '') === 'Afi Houngbo-Dossou' && $r['body']['data']['lang'] === 'fon', $r['raw']);
$r = call('PATCH', '/v1/me', ['role' => 'admin'], $token);
check('le rôle ne se change pas par /v1/me', Database::value('SELECT role FROM users WHERE email = ?', [$email]) === 'client');

$r = call('POST', '/v1/auth/forgot-password', ['email' => $email]);
$unknown = call('POST', '/v1/auth/forgot-password', ['email' => "inconnu-$stamp@example.com"]);
check('mot de passe oublié : même réponse, compte connu ou non', $r['status'] === 200 && $r['body']['data'] === $unknown['body']['data']);
$second = login($email, 'motdepasse-1');
$r = call('POST', '/v1/auth/reset-password', ['email' => $email, 'code' => lastCode($email, 'reset_password'), 'password' => 'nouveau-mdp-2', 'device' => 'simulation']);
$token = (string) ($r['body']['data']['token'] ?? '');
check('nouveau mot de passe : nouvelle session', $r['status'] === 200 && $token !== '', $r['raw']);
check('les autres sessions sont fermées', call('GET', '/v1/me', null, $second)['status'] === 401);
check('ancien mot de passe refusé, nouveau accepté', call('POST', '/v1/auth/login', ['email' => $email, 'password' => 'motdepasse-1'])['status'] === 401 && login($email, 'nouveau-mdp-2') !== '');
$r = call('POST', '/v1/auth/change-password', ['current_password' => 'faux', 'new_password' => 'encore-un-3'], $token);
check('changement avec mauvais mot de passe actuel : 422 (pas 401)', $r['status'] === 422);
$r = call('POST', '/v1/auth/change-password', ['current_password' => 'nouveau-mdp-2', 'new_password' => 'encore-un-3'], $token);
check('changement de mot de passe : la session en cours reste ouverte', $r['status'] === 200 && call('GET', '/v1/me', null, $token)['status'] === 200);
$r = call('POST', '/v1/auth/resend-code', ['email' => $email, 'purpose' => 'reset_password']);
check('renvoi de code trop rapide : 429 avec retry_after', $r['status'] === 429 && ($r['body']['error']['retry_after'] ?? 0) > 0, $r['raw']);
call('POST', '/v1/auth/logout', null, $token);
check('déconnexion : jeton révoqué en base et refusé (401 UNAUTHENTICATED)', Database::value('SELECT revoked_at FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]) !== null
    && call('GET', '/v1/me', null, $token)['body']['error']['code'] === 'UNAUTHENTICATED');
check('route protégée sans jeton : 401', call('GET', '/v1/me')['status'] === 401);

// ---------------------------------------------------------------------------
section('2. Enrôlement d\'un commerçant par un agent');
[$futureToken, $futureId] = newClient('commercante', $phone(3));
$r = call('POST', '/v1/agent-visits', null, $futureToken);
check('demande de visite d\'agent enregistrée', $r['status'] === 200 && Database::value('SELECT status FROM agent_visit_requests WHERE user_id = ?', [$futureId]) === 'new');
check('un client n\'accède pas à l\'espace agent : 403 FORBIDDEN', call('GET', '/v1/admin/users?search=sim', null, $futureToken)['status'] === 403);
check('un client n\'accède pas à l\'espace commerçant : 403', call('GET', '/v1/merchant/today', null, $futureToken)['status'] === 403);
$agent = login('agent@example.com');
$agentId = (int) Database::value('SELECT id FROM users WHERE email = ?', ['agent@example.com']);
$r = call('GET', '/v1/admin/users?search=' . urlencode("sim-commercante-$stamp"), null, $agent);
check('l\'agent trouve la cliente par son email', ($r['body']['data']['users'][0]['id'] ?? '') === "usr_$futureId", $r['raw']);
$r = call('POST', '/v1/admin/merchants', ['user_id' => "usr_$futureId", 'stall' => [
    'name' => "Étal simulation $stamp", 'tagline' => 'Pagnes et bazin', 'site_id' => 'yoyo', 'aisle_id' => 'tissus',
    'row' => 'Allée S', 'stand' => '7', 'has_physical_place' => true, 'merchant_type' => 'retail', 'pay_on_delivery' => true,
]], $agent);
$stallId = (int) substr((string) ($r['body']['data']['stall']['id'] ?? 'stl_0'), 4);
check('création de l\'étal : 201, compte passé en commerçant', $r['status'] === 201 && ($r['body']['data']['user']['role'] ?? '') === 'merchant', $r['raw']);
$stall = Database::one('SELECT * FROM stalls WHERE id = ?', [$stallId]);
check('base : étal vérifié par cet agent, contacts repris du compte', $stall !== null && (int) $stall['verified_by'] === $agentId && $stall['verified_at'] !== null && $stall['phone'] === $phone(3));
check('base : demande de visite close', Database::value('SELECT status FROM agent_visit_requests WHERE user_id = ?', [$futureId]) === 'done');
check('base : action journalisée (merchant.create)', Database::value('SELECT COUNT(*) FROM admin_actions WHERE actor_id = ? AND action = ? AND target_id = ?', [$agentId, 'merchant.create', $stallId]) === 1);
$r = call('POST', '/v1/admin/merchants', ['user_id' => "usr_$futureId", 'stall' => ['name' => 'X', 'site_id' => 'yoyo', 'aisle_id' => 'tissus']], $agent);
check('deuxième création : 409 ALREADY_MERCHANT', $r['status'] === 409);

$img = imagecreatetruecolor(1080, 1080);
for ($y = 0; $y < 1080; $y += 6) {
    imagefilledrectangle($img, 0, $y, 1080, $y + 5, imagecolorallocate($img, ($y * 3) % 255, 90 + $y % 120, ($y * 7) % 255));
}
$photo = sys_get_temp_dir() . "/axi-sim-$stamp.webp";
imagewebp($img, $photo, 90);
$r = call('POST', "/v1/admin/stalls/stl_$stallId/products", ['name' => 'Wax hollandais, 6 yards', 'price' => '18000', 'is_today' => '1', 'photo' => new CURLFile($photo, 'image/webp', 'photo.webp')], $agent, [], true);
$waxId = (int) substr((string) ($r['body']['data']['id'] ?? 'prd_0'), 4);
check('l\'agent saisit le premier produit avec photo : 201', $r['status'] === 201 && ($r['body']['data']['price']['amount'] ?? 0) === 18000, $r['raw']);
$product = Database::one('SELECT * FROM products WHERE id = ?', [$waxId]);
$thumb = BASE_PATH . '/public/' . ($product['image_path'] ?? 'x') . '/thumb.webp';
$medium = BASE_PATH . '/public/' . ($product['image_path'] ?? 'x') . '/medium.webp';
check('photo : thumb ~200 px < 15 Ko et medium ~600 px < 60 Ko en WebP', is_file($thumb) && is_file($medium) && filesize($thumb) < 15000 && filesize($medium) < 60000
    && getimagesize($thumb)[0] === 200 && getimagesize($medium)[0] === 600 && getimagesize($medium)['mime'] === 'image/webp');
check('photo : l\'original n\'est pas gardé (2 fichiers seulement)', count(glob(dirname($thumb) . '/*')) === 2);
check('base : produit créé par l\'agent et journalisé', (int) $product['created_by'] === $agentId && Database::value('SELECT COUNT(*) FROM admin_actions WHERE action = ? AND target_id = ?', ['product.create', $waxId]) === 1);
$img = call('GET', '/' . $product['image_path'] . '/thumb.webp');
check('photo servie par le serveur web', $img['status'] === 200);
$r = call('PATCH', "/v1/admin/merchants/stl_$stallId", ['status' => 'suspended'], $agent);
check('un agent ne peut pas suspendre un compte (admin seulement)', $r['status'] === 403);
$r = call('PATCH', "/v1/admin/merchants/usr_$futureId", ['stall' => ['stand' => '9']], $agent);
check('changement de stand = déménagement enregistré', ($r['body']['data']['stall']['location']['stand'] ?? '') === '9' && Database::value('SELECT moved_at FROM stalls WHERE id = ?', [$stallId]) !== null, $r['raw']);

// ---------------------------------------------------------------------------
section('3. Le commerçant gère lui-même son étal et ses prix');
$merchant = $futureToken; // même compte, rôle relu à chaque requête
$r = call('GET', '/v1/merchant/stall', null, $merchant);
check('« Mon étal » : le produit de l\'agent est là', count($r['body']['data']['products'] ?? []) === 1, $r['raw']);
$r = call('POST', '/v1/merchant/products', ['name' => 'Bazin riche, 5 m', 'price' => '', 'is_today' => '0'], $merchant, [], true);
$bazinId = (int) substr((string) ($r['body']['data']['id'] ?? 'prd_0'), 4);
check('produit « Prix à débattre » (price null)', $r['status'] === 201 && $r['body']['data']['price'] === null, $r['raw']);
$r = call('POST', '/v1/merchant/products', ['name' => 'Pagne tissé', 'price' => '12000', 'price_max' => '15000', 'is_today' => '1'], $merchant, [], true);
$pagneId = (int) substr((string) ($r['body']['data']['id'] ?? 'prd_0'), 4);
check('fourchette de prix 12 000 – 15 000', ($r['body']['data']['price']['amount_max'] ?? 0) === 15000, $r['raw']);
$r = call('PATCH', "/v1/merchant/products/prd_$waxId", ['price' => 19000], $merchant);
check('le commerçant change son prix (19 000)', ($r['body']['data']['price']['amount'] ?? 0) === 19000 && (int) Database::value('SELECT price_amount FROM products WHERE id = ?', [$waxId]) === 19000);
$r = call('PATCH', '/v1/merchant/products/prd_1', ['price' => 1], $merchant);
check('il ne peut pas toucher au produit d\'un autre étal : 404', $r['status'] === 404);
$r = call('PATCH', '/v1/merchant/stall', ['delivery_note' => 'Livraison Calavi', 'stand' => '999'], $merchant);
check('vitrine modifiée, emplacement inchangé (réservé à l\'agent)', ($r['body']['data']['stall']['delivery_note'] ?? '') === 'Livraison Calavi' && Database::value('SELECT stand FROM stalls WHERE id = ?', [$stallId]) === '9');
$r = call('GET', "/v1/stalls/stl_$stallId");
check('page publique : produits du jour en premier, badge vérifié', ($r['body']['data']['products'][0]['is_today'] ?? false) === true && $r['body']['data']['stall']['verified'] === true, $r['raw']);

// ---------------------------------------------------------------------------
section('4. Commande multi-étals et paiement : jamais deux débits');
[$client, $clientId] = newClient('cliente', $phone(4));
$kossiRice = (int) Database::value("SELECT p.id FROM products p JOIN stalls s ON s.id = p.stall_id WHERE s.name = 'Ets Kossi et Fils' AND p.name = 'Sac de riz, 25 kg'");
$lines = [['product_id' => "prd_$waxId", 'quantity' => 2], ['product_id' => "prd_$kossiRice", 'quantity' => 1]];
$r = call('POST', '/v1/orders/quote', ['lines' => array_merge($lines, [['product_id' => "prd_$bazinId", 'quantity' => 1]]), 'reception' => ['mode' => 'delivery', 'detail' => '']]);
$q = $r['body']['data'] ?? [];
check('devis : 2 étals, regroupement 600, livraison 1 500, prix débattu signalé', ($q['stall_count'] ?? 0) === 2 && $q['grouping_fee'] === 600 && $q['reception_fee'] === 1500
    && $q['items_total'] === 2 * 19000 + 17500 && $q['total'] === 2 * 19000 + 17500 + 600 + 1500 && $q['unavailable_product_ids'] === ["prd_$bazinId"], $r['raw']);
$order = ['lines' => $lines, 'reception' => ['mode' => 'delivery', 'detail' => 'Akpakpa, près de la pharmacie'], 'payment' => ['operator' => 'mtn', 'phone' => $phone(4)]];
$key = "sim-$stamp-1";
$first = call('POST', '/v1/orders', $order, $client, ['Idempotency-Key' => $key]);
$orderId = (int) substr((string) ($first['body']['data']['id'] ?? 'cmd_0'), 4);
check('commande créée : 201, en attente de validation Mobile Money', $first['status'] === 201 && $first['body']['data']['status'] === 'awaiting_payment', $first['raw']);
$again = call('POST', '/v1/orders', $order, $client, ['Idempotency-Key' => $key]);
check('même clé renvoyée (réseau coupé, l\'appli réessaie) : même commande, 200', $again['status'] === 200 && ($again['body']['data']['id'] ?? '') === "cmd_$orderId" && ($again['headers']['idempotent-replayed'] ?? '') === 'true', $again['raw']);
$burst = parallel(6, '/v1/orders', $order, $client, ['Idempotency-Key' => $key]);
check('6 envois simultanés avec la même clé : toujours la même commande', count(array_unique(array_map(static fn (array $b): string => $b['body']['data']['id'] ?? 'erreur', $burst))) === 1 && ($burst[0]['body']['data']['id'] ?? '') === "cmd_$orderId", json_encode($burst));
check('base : une seule commande et un seul paiement pour cette clé', Database::value('SELECT COUNT(*) FROM orders WHERE client_id = ? AND idempotency_key = ?', [$clientId, $key]) === 1
    && Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$orderId]) === 1);
check('prestataire : UNE seule demande de débit envoyée au téléphone', debits("axi_pay_$orderId") === 1, 'demandes : ' . debits("axi_pay_$orderId"));
$other = call('POST', '/v1/orders', array_merge($order, ['lines' => [['product_id' => "prd_$pagneId", 'quantity' => 1]]]), $client, ['Idempotency-Key' => $key]);
check('même clé pour un autre panier : refusé (422 IDEMPOTENCY_KEY_REUSED)', $other['status'] === 422 && $other['body']['error']['code'] === 'IDEMPOTENCY_KEY_REUSED');

$noKey = ['lines' => [['product_id' => "prd_$pagneId", 'quantity' => 1]], 'reception' => ['mode' => 'axi_point', 'detail' => 'pt_1'], 'payment' => ['operator' => 'moov', 'phone' => $phone(4)]];
$burst = parallel(5, '/v1/orders', $noKey, $client);
$ids = array_unique(array_map(static fn (array $b): string => $b['body']['data']['id'] ?? 'erreur', $burst));
$noKeyId = (int) substr((string) reset($ids), 4);
check('double appui SANS clé (5 envois simultanés) : une seule commande', count($ids) === 1 && $noKeyId > 0, json_encode($burst));
check('prestataire : une seule demande de débit pour ce panier', debits("axi_pay_$noKeyId") === 1);

$outage = ['lines' => [['product_id' => "prd_$waxId", 'quantity' => 1]], 'reception' => ['mode' => 'bus', 'detail' => 'Parakou'], 'payment' => ['operator' => 'celtiis', 'phone' => FakePaymentProvider::NETWORK_FAILURE_PHONE]];
$r = call('POST', '/v1/orders', $outage, $client, ['Idempotency-Key' => "sim-$stamp-panne"]);
$outageId = (int) substr((string) ($r['body']['data']['id'] ?? 'cmd_0'), 4);
check('coupure réseau vers le prestataire : commande gardée, aucun débit, message « réessayez »', $r['status'] === 201 && debits("axi_pay_$outageId") === 0
    && str_contains((string) $r['body']['data']['payment_instructions'], 'qu\'une seule fois'), $r['raw']);
$r = call('POST', '/v1/orders', $outage, $client, ['Idempotency-Key' => "sim-$stamp-panne"]);
call('POST', '/v1/orders', $outage, $client, ['Idempotency-Key' => "sim-$stamp-panne"]);
check('nouvel essai après la coupure : même commande, débit demandé UNE fois', ($r['body']['data']['id'] ?? '') === "cmd_$outageId" && debits("axi_pay_$outageId") === 1, $r['raw']);

$r = call('GET', "/v1/orders/cmd_$orderId", null, $client);
check('suivi : paiement reçu, argent gardé par Axi', ($r['body']['data']['status'] ?? '') === 'paid' && $r['body']['data']['payment_instructions'] === null, $r['raw']);
call('GET', "/v1/orders/cmd_$orderId", null, $client);
call('GET', "/v1/orders/cmd_$orderId", null, $client);
check('base : paiement « held », part de chaque étal « à préparer »', Database::value('SELECT status FROM payments WHERE order_id = ?', [$orderId]) === 'held'
    && Database::value("SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND status = 'to_prepare'", [$orderId]) === 2);
check('base : la cliente entre UNE fois dans le fichier de chaque commerçant (3 consultations)', Database::value('SELECT orders_count FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$stallId, $clientId]) === 1);
check('base : lignes avec le prix du moment (19 000 × 2)', Database::value('SELECT SUM(line_total) FROM order_items oi JOIN order_stalls os ON os.id = oi.order_stall_id WHERE os.order_id = ? AND os.stall_id = ?', [$orderId, $stallId]) == 38000);
check('une autre cliente ne voit pas la commande : 404', call('GET', "/v1/orders/cmd_$orderId", null, login('sena@example.com'))['status'] === 404);
$r = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$bazinId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Cotonou'], 'payment' => ['operator' => 'mtn', 'phone' => $phone(4)]], $client);
check('produit à prix débattu : 422 PRODUCT_UNAVAILABLE', $r['status'] === 422 && $r['body']['error']['code'] === 'PRODUCT_UNAVAILABLE');

// ---------------------------------------------------------------------------
section('5. Préparation, enlèvement, réception : argent libéré');
$r = call('GET', '/v1/merchant/today', null, $merchant);
$today = $r['body']['data'] ?? [];
check('« Ma journée » : la commande est à préparer, ventes du jour 38 000', in_array("cmd_$orderId", array_column($today['orders'] ?? [], 'id'), true) && $today['sales_today'] >= 38000, $r['raw']);
$pendingBefore = (int) call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['pending_amount'];
check('« Mon argent » : 38 000 à recevoir', $pendingBefore >= 38000);
check('« Prêt pour le coursier »', call('POST', "/v1/merchant/orders/cmd_$orderId/ready", null, $merchant)['body']['data']['status'] === 'ready');
check('statut global : en préparation', Database::value('SELECT status FROM orders WHERE id = ?', [$orderId]) === 'preparing');
$r = call('POST', "/v1/admin/orders/cmd_$orderId/pickup", ['stall_id' => "stl_$stallId"], $agent);
$kossiStall = (int) Database::value("SELECT id FROM stalls WHERE name = 'Ets Kossi et Fils'");
call('POST', "/v1/admin/orders/cmd_$orderId/pickup", ['stall_id' => "stl_$kossiStall"], $agent);
check('l\'agent récupère les deux parts : commande en route', Database::value('SELECT status FROM orders WHERE id = ?', [$orderId]) === 'on_the_way', $r['raw']);
$r = call('POST', "/v1/orders/cmd_$orderId/confirm-receipt", null, $client);
check('la cliente confirme la réception', ($r['body']['data']['status'] ?? '') === 'delivered', $r['raw']);
call('POST', "/v1/orders/cmd_$orderId/confirm-receipt", null, $client);
check('base : argent libéré une seule fois (payment released)', Database::value('SELECT status FROM payments WHERE order_id = ?', [$orderId]) === 'released'
    && Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$orderId]) === 1);
$pendingAfter = (int) call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['pending_amount'];
check('« Mon argent » : plus rien à recevoir pour cette commande', $pendingAfter === $pendingBefore - 38000, "$pendingBefore → $pendingAfter");

// ---------------------------------------------------------------------------
section('6. Clients, messages, crédit');
$r = call('GET', '/v1/merchant/clients?segment=all', null, $merchant);
check('« Mes clients » : la cliente y figure', in_array("Sim Cliente", array_column($r['body']['data']['clients'] ?? [], 'display_name'), true), $r['raw']);
$r = call('POST', '/v1/merchant/clients', ['display_name' => 'Tantine Rose', 'phone' => $phone(5), 'kind' => 'reseller'], $merchant);
check('ajout d\'un client à la main', $r['status'] === 201 && $r['body']['data']['badge']['kind'] === 'new');
check('doublon de client : 409', call('POST', '/v1/merchant/clients', ['display_name' => 'X', 'phone' => $phone(5), 'kind' => 'reseller'], $merchant)['status'] === 409);
$r = call('POST', '/v1/merchant/relocation/broadcast', null, $merchant);
check('nouvelle adresse envoyée à 2 clients, enregistrée', ($r['body']['data']['recipients'] ?? 0) === 2 && Database::value('SELECT relocation_sent_at FROM stalls WHERE id = ?', [$stallId]) !== null, $r['raw']);
check('crédit sans partage d\'historique : 422 DATA_SHARING_REQUIRED', call('POST', '/v1/merchant/credit-requests', null, $merchant)['body']['error']['code'] === 'DATA_SHARING_REQUIRED');
call('PUT', '/v1/merchant/data-sharing', ['enabled' => true], $merchant);
check('crédit après accord : demande enregistrée', call('POST', '/v1/merchant/credit-requests', null, $merchant)['status'] === 200 && Database::value('SELECT COUNT(*) FROM credits WHERE merchant_user_id = ?', [$futureId]) === 1);
$imf = login('imf@example.com');
$files = call('GET', '/v1/pro/financing', null, $imf)['body']['data']['shared_files'] ?? [];
check('l\'IMF voit le dossier partagé', in_array("Étal simulation $stamp", array_column($files, 'merchant_name'), true));
call('PUT', '/v1/merchant/data-sharing', ['enabled' => false], $merchant);
$files = call('GET', '/v1/pro/financing', null, $imf)['body']['data']['shared_files'] ?? [];
check('accord retiré : le dossier disparaît pour l\'IMF', !in_array("Étal simulation $stamp", array_column($files, 'merchant_name'), true));

// ---------------------------------------------------------------------------
section('7. Axi Pro : anonymat et droits');
$anagem = login('anagem@example.com');
$r = call('GET', '/v1/pro/overview', null, $anagem);
$medians = $r['body']['data']['median_prices'] ?? [];
check('aucun prix médian publié sur moins de 5 étals', array_filter($medians, static fn (array $m): bool => $m['sample_size'] < 5 && $m['median_price'] !== null) === [], json_encode($medians));
check('ANaGeM : recensement autorisé, financement refusé', call('GET', '/v1/pro/census', null, $anagem)['status'] === 200 && call('GET', '/v1/pro/financing', null, $anagem)['status'] === 403);
check('IMF : recensement refusé', call('GET', '/v1/pro/census', null, $imf)['status'] === 403);
$census = call('GET', '/v1/pro/census', null, $anagem)['body']['data'] ?? [];
check('recensement : aucune ligne de moins de 5 étals', array_filter(array_merge($census['by_site'] ?? [], $census['by_aisle'] ?? []), static fn (array $row): bool => $row['stalls'] < 5) === []);
check('un commerçant n\'accède pas à Axi Pro', call('GET', '/v1/pro/overview', null, $merchant)['status'] === 403);

// ---------------------------------------------------------------------------
section('8. Cache, CORS, tâche planifiée, sécurité');
$home = call('GET', '/v1/home');
$etag = $home['headers']['etag'] ?? '';
check('GET /v1/home : ETag et Cache-Control', $etag !== '' && str_contains($home['headers']['cache-control'] ?? '', 'max-age=300'));
check('If-None-Match identique : 304 sans corps', ($r = call('GET', '/v1/home', null, null, ['If-None-Match' => $etag]))['status'] === 304 && $r['raw'] === '');
$r = call('OPTIONS', '/v1/orders', null, null, ['Origin' => 'http://localhost:8081']);
check('CORS : origine autorisée, Idempotency-Key acceptée', ($r['headers']['access-control-allow-origin'] ?? '') === 'http://localhost:8081' && str_contains($r['headers']['access-control-allow-headers'] ?? '', 'Idempotency-Key'));
check('CORS : origine inconnue refusée', !isset(call('OPTIONS', '/v1/orders', null, null, ['Origin' => 'https://pirate.example'])['headers']['access-control-allow-origin']));
check('tâche planifiée par URL : mauvaise clé = 404', call('GET', '/v1/internal/cron?key=mauvaise')['status'] === 404);
check('tâche planifiée par URL : bonne clé = 200', call('GET', '/v1/internal/cron?key=' . urlencode((string) \Axi\Core\Config::get('cron_key')))['status'] === 200);
check('les secrets ne sont jamais servis (config.local.php)', !str_contains(call('GET', '/config/config.local.php')['raw'], (string) \Axi\Core\Config::get('db.pass') . "'"));
check('les journaux ne sont jamais servis (storage/logs)', !str_contains(call('GET', '/storage/logs/codes.log')['raw'], 'verify_email'));
check('aucune erreur serveur pendant la simulation', !str_contains((string) @file_get_contents(BASE_PATH . '/storage/logs/error.log'), date('Y-m-d H:', time())), 'voir storage/logs/error.log');

echo "\n" . ($failures === 0 ? "RÉSULTAT : OK, $checks vérifications sur $checks passent." : "RÉSULTAT : ÉCHEC, $failures vérification(s) sur $checks en erreur.") . "\n";
exit($failures === 0 ? 0 : 1);
