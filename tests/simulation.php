<?php
declare(strict_types=1);

// Simulation complète des parcours Axi contre l'API et l'espace web en marche, avec vérification
// des enregistrements en base. Usage : php tests/simulation.php [URL]   (défaut http://localhost:8000)
// Prérequis : env local, base remise à zéro (database/reset.sql). Paiements : passerelle factice
// (en-tête X-Axi-Test-Gateway, accepté seulement en local), sauf la section FedaPay sandbox réelle.
// Chaque lancement crée de nouveaux comptes (adresses @example.com, aucun email réel).

use Axi\Core\Database;
use Axi\Services\Payment\FakeGateway;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$stamp = (string) time();
$failures = 0;
$checks = 0;
$jar = sys_get_temp_dir() . "/axi-sim-cookies-$stamp";

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
function call(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = [], bool $multipart = false, bool $web = false): array
{
    global $api, $jar;
    $ch = curl_init($api . $path);
    $h = $web ? [] : ['Accept: application/json', 'X-Axi-Test-Gateway: fake'];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    if ($body !== null) {
        $hasFile = is_array($body) && array_filter($body, static fn ($v) => $v instanceof CURLFile) !== [];
        if ($web && !$hasFile) {
            // Formulaire web sans fichier : encodé comme un navigateur (tableaux v[clé] compris)
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } elseif ($multipart || $web) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    $raw = (string) curl_exec($ch);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => json_decode($raw, true) ?? [], 'headers' => $responseHeaders, 'raw' => $raw];
}

function parallel(int $n, string $path, array $body, string $token, array $headers = []): array
{
    global $api;
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init($api . $path);
        $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'X-Axi-Test-Gateway: fake'];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    return array_map(static fn ($ch): array => ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => json_decode((string) curl_multi_getcontent($ch), true) ?? []], $handles);
}

function lastCode(string $email, string $purpose): ?string
{
    foreach (array_reverse(@file(BASE_PATH . '/storage/logs/codes.log', FILE_IGNORE_NEW_LINES) ?: []) as $line) {
        if (preg_match('/ ' . preg_quote($email, '/') . ' ' . $purpose . ' (\d{6})$/', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

/** Crée un compte confirmé (client ou commerçant) et renvoie [jeton, id numérique, email] */
function newAccount(string $tag, string $profile): array
{
    global $stamp;
    $email = "sim-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => 'Sim', 'last_name' => ucfirst($tag), 'email' => $email, 'password' => 'simulation-123', 'profile' => $profile]);
    $r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email, 'verify_email'), 'device' => 'simulation']);
    return [(string) ($r['body']['data']['token'] ?? ''), (int) substr((string) ($r['body']['data']['user']['id'] ?? 'usr_0'), 4), $email];
}

/** Connexion web d'un partenaire (cookie de session + jeton CSRF) */
function webLogin(string $email, string $password): string
{
    global $jar;
    @unlink($jar); // nouvelle session : la page de connexion redirige un partenaire déjà connecté
    $page = call('GET', '/pro/connexion', null, null, [], false, true)['raw'];
    preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
    call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => $email, 'password' => $password], null, [], false, true);
    preg_match('/name="_csrf" value="([^"]+)"/', call('GET', '/pro', null, null, [], false, true)['raw'], $m);
    return $m[1] ?? '';
}

function debits(string $reference): int
{
    return substr_count((string) @file_get_contents(BASE_PATH . '/storage/logs/payments.log'), "DEMANDE DE DÉBIT $reference ");
}

function id(string $publicId): int
{
    return (int) substr($publicId, strpos($publicId, '_') + 1);
}

$photo = sys_get_temp_dir() . "/axi-sim-$stamp.webp";
$img = imagecreatetruecolor(1080, 1080);
for ($y = 0; $y < 1080; $y += 6) {
    imagefilledrectangle($img, 0, $y, 1080, $y + 5, imagecolorallocate($img, ($y * 3) % 255, 90 + $y % 120, ($y * 7) % 255));
}
imagewebp($img, $photo, 90);

if (Database::value("SELECT id FROM users WHERE email = 'partenaire@example.com'") === null) {
    exit("Base non initialisée : importez database/reset.sql.\n");
}
echo "Simulation Axi contre $api\n";

// ---------------------------------------------------------------------------
section('1. Comptes : email et mot de passe uniquement');
$email = "sim-auth-$stamp@example.com";
$r = call('POST', '/v1/auth/register', ['first_name' => ' Afi ', 'last_name' => 'Houngbo', 'email' => strtoupper($email), 'password' => 'motdepasse-1', 'profile' => 'client']);
check('inscription client sans téléphone : 201, compte non confirmé', $r['status'] === 201 && $r['body']['data']['user']['role'] === 'client' && $r['body']['data']['user']['phone'] === null, $r['raw']);
$user = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
check('base : email en minuscules, mot de passe haché, paramètres créés', $user !== null && $user['first_name'] === 'Afi' && str_starts_with($user['password_hash'], '$2y$')
    && Database::value('SELECT user_id FROM user_settings WHERE user_id = ?', [$user['id'] ?? 0]) !== null);
check('profil invalide refusé (seuls client et commerçant s\'inscrivent)', call('POST', '/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'email' => "p-$email", 'password' => 'motdepasse-1', 'profile' => 'partner'])['status'] === 422);
check('aucune route de connexion par téléphone ou SMS', call('POST', '/v1/auth/otp', ['phone' => '+22997000000'])['status'] === 404 && call('POST', '/v1/auth/verify', [])['status'] === 404);
$code = lastCode($email, 'verify_email');
check('base : code stocké en HMAC, pas en clair', Database::value('SELECT code_hash FROM email_codes WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]) !== $code);
check('connexion avant confirmation : 403 EMAIL_NOT_VERIFIED', call('POST', '/v1/auth/login', ['email' => $email, 'password' => 'motdepasse-1'])['body']['error']['code'] === 'EMAIL_NOT_VERIFIED');
$r = call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => $code, 'device' => 'simulation']);
$token = (string) ($r['body']['data']['token'] ?? '');
check('confirmation : jeton de 64 caractères, seul son sha256 en base', preg_match('/^[a-f0-9]{64}$/', $token) === 1 && Database::value('SELECT id FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]) !== null);
check('base : connexion réussie journalisée', Database::value('SELECT COUNT(*) FROM login_events WHERE user_id = ? AND success = 1', [$user['id']]) >= 1);
check('partenaire refusé dans l\'application (403 PARTNER_USE_WEB)', call('POST', '/v1/auth/login', ['email' => 'partenaire@example.com', 'password' => 'Partenaire-2026!'])['body']['error']['code'] === 'PARTNER_USE_WEB');
check('mauvais mot de passe : 401 et échec journalisé', call('POST', '/v1/auth/login', ['email' => $email, 'password' => 'faux-mot-de-passe'])['status'] === 401
    && Database::value('SELECT COUNT(*) FROM login_events WHERE email = ? AND success = 0', [$email]) >= 1);
$r = call('PATCH', '/v1/me/settings', ['theme' => 'dark', 'data_cap_mb' => 800, 'notifications' => ['promotion' => ['email' => true]], 'default_address' => 'Akpakpa'], $token);
check('paramètres enregistrés en base (thème, plafond, notifications, adresse)', ($r['body']['data']['theme'] ?? '') === 'dark' && Database::value('SELECT theme FROM user_settings WHERE user_id = ?', [$user['id']]) === 'dark'
    && $r['body']['data']['notifications']['promotion']['email'] === true, $r['raw']);
call('POST', '/v1/me/data-usage', ['bytes' => 2 * 1048576], $token);
check('consommation de données enregistrée', (int) Database::value('SELECT data_used_bytes FROM user_settings WHERE user_id = ?', [$user['id']]) === 2 * 1048576);
check('sessions et historique des connexions', count(call('GET', '/v1/me/sessions', null, $token)['body']['data']['sessions'] ?? []) >= 1 && count(call('GET', '/v1/me/login-history', null, $token)['body']['data']['events'] ?? []) >= 2);
$export = call('POST', '/v1/me/export', null, $token)['body']['data']['url'] ?? '';
check('export de mes données (lien signé)', str_contains(call('GET', substr($export, strlen($api)))['raw'], '"email": "' . $email . '"'));
$second = (string) (call('POST', '/v1/auth/login', ['email' => $email, 'password' => 'motdepasse-1', 'device' => 'autre'])['body']['data']['token'] ?? '');
call('POST', '/v1/auth/logout-all', null, $token);
check('déconnexion de tous les appareils', call('GET', '/v1/me', null, $token)['status'] === 401 && call('GET', '/v1/me', null, $second)['status'] === 401);

// ---------------------------------------------------------------------------
section('2. Commerçant : inscription, étal, produits en photo et en vocal');
[$merchant, $merchantId, $merchantEmail] = newAccount('commercante', 'merchant');
check('inscription commerçant : rôle merchant, sans étal', call('GET', '/v1/me', null, $merchant)['body']['data']['merchant']['has_stall'] === false);
check('espace commerçant sans étal : 404 STALL_REQUIRED', call('GET', '/v1/merchant/today', null, $merchant)['body']['error']['code'] === 'STALL_REQUIRED');
$options = call('GET', '/v1/merchant/stall-options', null, $merchant)['body']['data'] ?? [];
check('listes des sites et allées (gérées en base)', count($options['sites'] ?? []) >= 4 && count($options['aisles'] ?? []) >= 7);
$r = call('POST', '/v1/merchant/stall', ['name' => "Étal sim $stamp", 'tagline' => 'Pagnes et bazin', 'site_id' => 'yoyo', 'aisle_id' => 'tissus', 'row' => 'Allée S', 'stand' => '7', 'merchant_type' => 'wholesale', 'pay_on_delivery' => true, 'phone' => '0197' . substr($stamp, -6)], $merchant);
$stallId = id((string) ($r['body']['data']['stall']['id'] ?? 'stl_0'));
check('création de l\'étal : non vérifié, vérification demandée à un agent', $r['status'] === 201 && $r['body']['data']['stall']['verified'] === false && $r['body']['data']['stall']['verification_requested'] === true, $r['raw']);
$r = call('POST', '/v1/merchant/stall/photo', ['photo' => new CURLFile($photo, 'image/webp')], $merchant, [], true);
check('photo de l\'étal', ($r['body']['data']['stall']['avatar']['medium_url'] ?? null) !== null, $r['raw']);
$r = call('POST', '/v1/merchant/products', ['name' => 'Wax hollandais, 6 yards', 'price' => '18000', 'is_today' => '1', 'photos[0]' => new CURLFile($photo, 'image/webp'), 'photos[1]' => new CURLFile($photo, 'image/webp')], $merchant, [], true);
$waxId = id((string) ($r['body']['data']['id'] ?? 'prd_0'));
check('produit en photo : 2 photos, image principale = 1re photo', count($r['body']['data']['images'] ?? []) === 2 && ($r['body']['data']['image']['thumb_url'] ?? '') === ($r['body']['data']['images'][0]['thumb_url'] ?? 'x'), $r['raw']);
$thumb = parse_url((string) ($r['body']['data']['image']['thumb_url'] ?? ''), PHP_URL_PATH);
check('image servie (URL absolue) : 200, WebP ~200 px < 15 Ko', call('GET', (string) $thumb)['status'] === 200 && getimagesize(BASE_PATH . '/public' . $thumb)[0] === 200 && filesize(BASE_PATH . '/public' . $thumb) < 15000);
$r = call('POST', '/v1/merchant/products/voice', ['audio' => new CURLFile(__DIR__ . '/fixtures/voix.m4a', 'audio/mp4', 'voix.m4a')], $merchant, [], true);
$draftId = id((string) ($r['body']['data']['id'] ?? 'prd_0'));
check('produit dicté en vocal : brouillon non publié', $r['status'] === 201 && $r['body']['data']['status'] === 'draft', $r['raw']);
check('brouillon invisible des clients', call('GET', "/v1/products/prd_$draftId")['status'] === 404);
check('publication refusée tant que le brouillon n\'a pas de nom', call('PATCH', "/v1/merchant/products/prd_$draftId", ['status' => 'active'], $merchant)['status'] === 422);
$r = call('PATCH', "/v1/merchant/products/prd_$draftId", ['name' => 'Bazin riche, 5 m', 'price' => 25000, 'status' => 'active'], $merchant);
check('brouillon complété puis publié', ($r['body']['data']['status'] ?? '') === 'active', $r['raw']);
$r = call('PATCH', "/v1/merchant/products/prd_$waxId", ['price' => 19000], $merchant);
check('le commerçant fixe lui-même son prix', (int) Database::value('SELECT price_amount FROM products WHERE id = ?', [$waxId]) === 19000);
$r = call('POST', '/v1/merchant/products', ['name' => 'Pagne tissé', 'price' => '', 'is_today' => '0'], $merchant, [], true);
$debatId = id((string) ($r['body']['data']['id'] ?? 'prd_0'));
check('produit « Prix à débattre »', $r['status'] === 201 && $r['body']['data']['price'] === null);

// ---------------------------------------------------------------------------
section('3. Espace web : un agent Axi vérifie l\'étal sur place, le badge apparaît dans l\'app');
check('page publique avant vérification : pas de badge', call('GET', "/v1/stalls/stl_$stallId")['body']['data']['stall']['verified'] === false);
$page = call('GET', '/pro/connexion', null, null, [], false, true)['raw'];
preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
check('commerçant refusé sur l\'espace web partenaire', str_contains(call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => $merchantEmail, 'password' => 'simulation-123'], null, [], false, true)['raw'], 'réservé aux partenaires'));
$csrf = webLogin('partenaire@example.com', 'Partenaire-2026!');
check('connexion web de l\'agent Axi', $csrf !== '' && str_contains(call('GET', '/pro', null, null, [], false, true)['raw'], 'Bonjour Agent'));
check('la vérification à faire apparaît dans l\'espace web', str_contains(call('GET', '/pro/verifications', null, null, [], false, true)['raw'], "Étal sim $stamp"));
check('formulaire sans jeton CSRF refusé', call('POST', "/pro/commercants/$stallId/verifier", ['row' => 'A'], null, [], false, true)['status'] === 419);
$r = call('POST', "/pro/commercants/$stallId/verifier", ['_csrf' => $csrf, 'row' => 'Allée S', 'stand' => '7', 'photo' => new CURLFile($photo, 'image/webp')], null, [], false, true);
check('vérification avec photo à l\'emplacement réel', in_array($r['status'], [302, 303], true) && Database::value('SELECT verified_at FROM stalls WHERE id = ?', [$stallId]) !== null
    && Database::value('SELECT verification_photo_path FROM stalls WHERE id = ?', [$stallId]) !== null, (string) $r['status']);
check('badge « vérifié » visible dans l\'application', call('GET', "/v1/stalls/stl_$stallId")['body']['data']['stall']['verification']['by'] === 'agent');
check('action journalisée (admin_actions) et commerçant notifié', Database::value("SELECT COUNT(*) FROM admin_actions WHERE action = 'stall.verify' AND target_id = ?", [$stallId]) === 1
    && Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Votre étal est vérifié'", [$merchantId]) === 1);
$r = call('PATCH', '/v1/merchant/stall', ['stand' => '9'], $merchant);
check('le commerçant déménage : badge retiré jusqu\'à une nouvelle visite', $r['body']['data']['stall']['verified'] === false && $r['body']['data']['stall']['location']['moved_at'] !== null, $r['raw']);
call('POST', "/pro/commercants/$stallId/verifier", ['_csrf' => $csrf, 'row' => 'Allée S', 'stand' => '9', 'photo' => new CURLFile($photo, 'image/webp')], null, [], false, true);
check('nouvelle vérification : badge rendu', call('GET', "/v1/stalls/stl_$stallId")['body']['data']['stall']['verified'] === true);

// ---------------------------------------------------------------------------
section('4. Client : visite, favoris, panier multi-étals, paiement unique, jamais deux débits');
[$second, $secondId] = newAccount('commercant2', 'merchant');
call('POST', '/v1/merchant/stall', ['name' => "Grains sim $stamp", 'site_id' => 'daho', 'aisle_id' => 'vivriers', 'stand' => '3'], $second);
$rice = id((string) (call('POST', '/v1/merchant/products', ['name' => 'Sac de riz, 25 kg', 'price' => '17500', 'is_today' => '1'], $second, [], true)['body']['data']['id'] ?? 'prd_0'));
[$client, $clientId] = newAccount('cliente', 'client');
check('visiteur : accueil et fiche produit sans compte', call('GET', '/v1/home')['status'] === 200 && call('GET', "/v1/products/prd_$waxId")['body']['data']['images'][0]['medium_url'] !== null);
check('visiteur : commander exige un compte (401)', call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$waxId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'x']])['status'] === 401);
call('GET', "/v1/products/prd_$waxId", null, $client);
call('POST', "/v1/favorites/prd_$waxId", null, $client);
check('récemment vus et favoris enregistrés', count(call('GET', '/v1/me/recently-viewed', null, $client)['body']['data']['products'] ?? []) === 1 && count(call('GET', '/v1/favorites', null, $client)['body']['data']['products'] ?? []) === 1);
call('POST', "/v1/stalls/stl_$stallId/follow", null, $client);
$lines = [['product_id' => "prd_$waxId", 'quantity' => 2], ['product_id' => "prd_$rice", 'quantity' => 1]];
$q = call('POST', '/v1/orders/quote', ['lines' => array_merge($lines, [['product_id' => "prd_$debatId", 'quantity' => 1]]), 'reception' => ['mode' => 'delivery', 'detail' => '']])['body']['data'] ?? [];
check('devis calculé avec les montants EN BASE (2 étals × 300, livraison 1 500)', ($q['grouping_fee'] ?? 0) === 600 && $q['reception_fee'] === 1500 && $q['total'] === 38000 + 17500 + 600 + 1500 && $q['unavailable_product_ids'] === ["prd_$debatId"], json_encode($q));
$order = ['lines' => $lines, 'reception' => ['mode' => 'delivery', 'detail' => 'Akpakpa'], 'payment' => ['operator' => 'mtn', 'phone' => '']];
$key = "sim-$stamp-1";
$first = call('POST', '/v1/orders', $order, $client, ['Idempotency-Key' => $key]);
$orderId = id((string) ($first['body']['data']['id'] ?? 'cmd_0'));
check('commande : 201, lien de paiement à ouvrir, référence fixe', $first['status'] === 201 && ($first['body']['data']['payment']['payment_url'] ?? null) !== null, $first['raw']);
$reference = (string) ($first['body']['data']['payment']['reference'] ?? '');
check('commission Axi (2 % en base) et part nette par étal', (int) Database::value('SELECT commission_total FROM orders WHERE id = ?', [$orderId]) === (int) round(38000 * 0.02) + (int) round(17500 * 0.02)
    && (int) Database::value('SELECT net_amount FROM order_stalls WHERE order_id = ? AND stall_id = ?', [$orderId, $stallId]) === 38000 - 760);
check('email de confirmation de commande (notification enregistrée)', Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = ?", [$clientId, "Commande cmd_$orderId enregistrée"]) === 1);
$burst = parallel(6, '/v1/orders', $order, $client, ['Idempotency-Key' => $key]);
check('6 envois simultanés, même clé : même commande', count(array_unique(array_map(static fn (array $b): string => $b['body']['data']['id'] ?? 'erreur', $burst))) === 1, json_encode($burst));
check('passerelle : UNE seule demande de débit', debits($reference) === 1 && Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$orderId]) === 1);
check('même clé, autre panier : 422 IDEMPOTENCY_KEY_REUSED', call('POST', '/v1/orders', array_merge($order, ['lines' => [['product_id' => "prd_$rice", 'quantity' => 3]]]), $client, ['Idempotency-Key' => $key])['body']['error']['code'] === 'IDEMPOTENCY_KEY_REUSED');
$noKey = ['lines' => [['product_id' => "prd_$rice", 'quantity' => 2]], 'reception' => ['mode' => 'axi_point', 'detail' => 'pt_1'], 'payment' => ['operator' => 'moov', 'phone' => '']];
$ids = array_unique(array_map(static fn (array $b): string => $b['body']['data']['id'] ?? 'erreur', parallel(5, '/v1/orders', $noKey, $client)));
check('double appui sans clé (5 envois) : une seule commande', count($ids) === 1 && reset($ids) !== 'erreur');
$outage = ['lines' => [['product_id' => "prd_$rice", 'quantity' => 1]], 'reception' => ['mode' => 'bus', 'detail' => 'Parakou'], 'payment' => ['operator' => 'celtiis', 'phone' => FakeGateway::NETWORK_FAILURE_PHONE]];
$r = call('POST', '/v1/orders', $outage, $client, ['Idempotency-Key' => "sim-$stamp-panne"]);
$outageRef = (string) Database::value('SELECT reference FROM payments WHERE order_id = ?', [id((string) ($r['body']['data']['id'] ?? 'cmd_0'))]);
check('coupure vers la passerelle : commande gardée, aucun débit, « réessayez »', debits($outageRef) === 0 && $r['body']['data']['payment']['payment_url'] === null, $r['raw']);
call('POST', '/v1/orders', $outage, $client, ['Idempotency-Key' => "sim-$stamp-panne"]);
call('POST', '/v1/orders', $outage, $client, ['Idempotency-Key' => "sim-$stamp-panne"]);
check('nouvel essai : débit demandé UNE fois', debits($outageRef) === 1);
$r = call('POST', "/v1/orders/cmd_$orderId/verify-payment", null, $client);
check('retour de paiement : statut vérifié auprès de la passerelle → payé', ($r['body']['data']['status'] ?? '') === 'paid', $r['raw']);
check('base : séquestre « held » par étal, événements journalisés', Database::value("SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND escrow_state = 'held'", [$orderId]) === 2
    && Database::value("SELECT COUNT(*) FROM payment_events WHERE order_id = ? AND event IN ('payment_paid','escrow_held')", [$orderId]) === 3);
call('POST', "/v1/orders/cmd_$orderId/verify-payment", null, $client);
check('vérification répétée : aucun double traitement', Database::value("SELECT COUNT(*) FROM payment_events WHERE order_id = ? AND event = 'payment_paid'", [$orderId]) === 1
    && Database::value('SELECT orders_count FROM merchant_clients WHERE stall_id = ? AND user_id = ?', [$stallId, $clientId]) === 1);
check('emails « paiement reçu » (client) et « nouvelle commande » (commerçants)', Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Paiement reçu'", [$clientId]) === 1
    && Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Nouvelle commande payée'", [$merchantId]) === 1);
check('annulation impossible une fois payée', call('POST', "/v1/orders/cmd_$orderId/cancel", null, $client)['status'] === 409);

// ---------------------------------------------------------------------------
section('5. Préparation, enlèvement par l\'agent, réception, libération, versement');
check('« Ma journée » : commande à préparer', in_array("cmd_$orderId", array_column(call('GET', '/v1/merchant/today', null, $merchant)['body']['data']['orders'] ?? [], 'id'), true));
check('« Mon argent » : 37 240 bloqués', (int) call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['pending_amount'] === 37240);
call('POST', "/v1/merchant/orders/cmd_$orderId/ready", null, $merchant);
foreach (Database::all('SELECT id FROM order_stalls WHERE order_id = ?', [$orderId]) as $part) {
    call('POST', "/pro/commandes/$orderId/enlevement", ['_csrf' => $csrf, 'part' => $part['id']], null, [], false, true);
}
check('l\'agent récupère les deux parts (web) : commande en route', Database::value('SELECT status FROM orders WHERE id = ?', [$orderId]) === 'on_the_way');
$r = call('POST', "/v1/orders/cmd_$orderId/confirm-receipt", null, $client);
check('« J\'ai bien reçu » : argent libéré aux deux commerçants', ($r['body']['data']['status'] ?? '') === 'delivered' && Database::value("SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND escrow_state = 'released'", [$orderId]) === 2, $r['raw']);
$money = call('GET', '/v1/merchant/money', null, $merchant)['body']['data'] ?? [];
check('« Mon argent » : libéré 37 240, plus rien de bloqué', ($money['released_amount'] ?? 0) === 37240 && $money['pending_amount'] === 0);
call('POST', "/pro/commercants/$stallId/verser", ['_csrf' => $csrf], null, [], false, true);
$payout = Database::one('SELECT * FROM payouts WHERE stall_id = ? ORDER BY id DESC LIMIT 1', [$stallId]);
check('versement au commerçant (simulé en test) et enregistré', $payout !== null && (int) $payout['amount'] === 37240 && $payout['status'] === 'simulated');
check('« Mon argent » : versé 37 240', (int) call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['paid_out_amount'] === 37240);
$sales = call('GET', '/v1/sales', null, $merchant)['body']['data'] ?? [];
check('« Ventes & paiements » du commerçant : montant, commission, net', ($sales['totals']['amount'] ?? 0) === 38000 && $sales['totals']['net_amount'] === 37240);
$csv = call('POST', '/v1/sales/export', null, $merchant)['body']['data']['url'] ?? '';
check('export CSV des ventes', str_contains(call('GET', substr($csv, strlen($api)))['raw'], "cmd_$orderId"));

// ---------------------------------------------------------------------------
section('6. Litige : argent bloqué, décision de l\'agent, remboursement');
$o2 = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$waxId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Cotonou'], 'payment' => ['operator' => 'mtn', 'phone' => '']], $client, ['Idempotency-Key' => "sim-$stamp-2"]);
$o2Id = id((string) ($o2['body']['data']['id'] ?? 'cmd_0'));
call('POST', "/v1/orders/cmd_$o2Id/verify-payment", null, $client);
$r = call('POST', "/v1/orders/cmd_$o2Id/dispute", ['reason' => 'Le pagne reçu n\'est pas celui de la photo.'], $client);
check('signalement d\'un litige : argent bloqué', ($r['body']['data']['status'] ?? '') === 'disputed' && Database::value('SELECT escrow_state FROM order_stalls WHERE order_id = ?', [$o2Id]) === 'disputed', $r['raw']);
check('« J\'ai bien reçu » refusé pendant le litige', call('POST', "/v1/orders/cmd_$o2Id/confirm-receipt", null, $client)['status'] === 409);
$disputeId = (int) Database::value('SELECT id FROM disputes WHERE order_id = ?', [$o2Id]);
call('POST', "/pro/litiges/$disputeId", ['_csrf' => $csrf, 'decision' => 'refund', 'amount' => '', 'note' => 'Produit non conforme : remboursement total.'], null, [], false, true);
check('décision : remboursement total (articles et frais)', Database::value('SELECT status FROM payments WHERE order_id = ?', [$o2Id]) === 'refunded'
    && (int) Database::value('SELECT SUM(r.amount) FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE p.order_id = ?', [$o2Id]) === 19000 + 1500
    && Database::value('SELECT status FROM orders WHERE id = ?', [$o2Id]) === 'refunded');
check('client notifié de la décision', Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Votre litige a été examiné'", [$clientId]) === 1);

// ---------------------------------------------------------------------------
section('7. Clients du commerçant, relances sans SMS, abonnement, crédit');
$clients = call('GET', '/v1/merchant/clients', null, $merchant)['body']['data']['clients'] ?? [];
$cli = $clients[0]['id'] ?? 'cli_0';
$fiche = call('GET', "/v1/merchant/clients/$cli", null, $merchant)['body']['data'] ?? [];
check('fiche client avec historique des commandes', ($fiche['has_axi_account'] ?? false) === true && count($fiche['orders'] ?? []) >= 1, json_encode($fiche));
$r = call('POST', "/v1/merchant/clients/$cli/follow-up", null, $merchant);
check('relance : notification dans l\'app + lien WhatsApp (aucun SMS)', ($r['body']['data']['notified'] ?? 0) === 1 && str_starts_with((string) $r['body']['data']['whatsapp_url'], 'https://wa.me/'), $r['raw']);
$r = call('POST', '/v1/merchant/clients', ['display_name' => 'Tantine Rose', 'email' => "rose-$stamp@example.com", 'kind' => 'reseller'], $merchant);
check('ajout manuel d\'un client (email, sans téléphone)', $r['status'] === 201, $r['raw']);
$r = call('POST', '/v1/merchant/relocation/broadcast', null, $merchant);
check('nouvelle adresse : app + email + lien WhatsApp', ($r['body']['data']['recipients'] ?? 0) === 2 && $r['body']['data']['notified'] === 1 && $r['body']['data']['emailed'] >= 1, $r['raw']);
$r = call('POST', '/v1/merchant/subscription/pay', null, $merchant);
$subRef = (string) ($r['body']['data']['reference'] ?? '');
check('abonnement grossiste (5 000 en base) : lien de paiement', ($r['body']['data']['amount'] ?? 0) === 5000 && $r['body']['data']['payment_url'] !== null, $r['raw']);
check('double appui sur « Payer l\'abonnement » : même paiement', (call('POST', '/v1/merchant/subscription/pay', null, $merchant)['body']['data']['reference'] ?? '') === $subRef);
$r = call('POST', '/v1/merchant/subscription/verify', ['reference' => $subRef], $merchant);
check('abonnement payé et actif 30 jours', ($r['body']['data']['subscription']['status'] ?? '') === 'active', $r['raw']);
check('crédit refusé sans consentement', call('POST', '/v1/merchant/credit-requests', null, $merchant)['body']['error']['code'] === 'DATA_SHARING_REQUIRED');
call('PUT', '/v1/merchant/data-sharing', ['enabled' => true], $merchant);
check('consentement enregistré (date, périmètre) puis demande de crédit', Database::value('SELECT scope FROM data_sharing_consents WHERE merchant_user_id = ? AND revoked_at IS NULL', [$merchantId]) !== null
    && call('POST', '/v1/merchant/credit-requests', ['amount' => 200000], $merchant)['status'] === 200);
$consents = call('GET', '/v1/me/consents', null, $merchant)['body']['data']['consents'] ?? [];
check('mes consentements visibles et révocables', ($consents[0]['active'] ?? false) === true);

// ---------------------------------------------------------------------------
section('8. Partenaires : IMF, ANaGeM, annonceur (données agrégées, consentement)');
webLogin('imf@example.com', 'Partenaire-2026!');
check('l\'IMF voit la demande et le dossier consenti', str_contains(call('GET', '/pro/credits', null, null, [], false, true)['raw'], "Étal sim $stamp"));
$consentId = (int) Database::value('SELECT id FROM data_sharing_consents WHERE merchant_user_id = ? AND revoked_at IS NULL', [$merchantId]);
check('historique du commerçant visible avec consentement', call('GET', "/pro/credits/dossier/$consentId", null, null, [], false, true)['status'] === 200);
call('DELETE', '/v1/me/consents/cst_' . $consentId, null, $merchant);
check('consentement retiré : dossier refusé à l\'IMF (403)', call('GET', "/pro/credits/dossier/$consentId", null, null, [], false, true)['status'] === 403);
check('l\'IMF n\'accède pas aux commerçants (403)', call('GET', '/pro/commercants', null, null, [], false, true)['status'] === 403);
webLogin('anagem@example.com', 'Partenaire-2026!');
check('ANaGeM : recensement agrégé, pas de fiche commerçant', call('GET', '/pro/recensement', null, null, [], false, true)['status'] === 200 && call('GET', "/pro/commercants/$stallId", null, null, [], false, true)['status'] === 403);
check('aucune statistique sur moins de 5 étals', !str_contains(call('GET', '/pro/recensement', null, null, [], false, true)['raw'], 'Par site'));
$csrf = webLogin('annonceur@example.com', 'Partenaire-2026!');
call('POST', '/pro/campagnes', ['_csrf' => $csrf, 'format' => 'sponsored_home', 'audience_type' => 'clients', 'aisle_id' => 'tissus', 'title' => "Promo sim $stamp", 'body' => 'Wax à prix doux', 'budget' => '60000'], null, [], false, true);
$campaign = (int) Database::value('SELECT id FROM campaigns WHERE title = ?', ["Promo sim $stamp"]);
check('annonceur : campagne créée « en relecture »', $campaign > 0 && Database::value('SELECT status FROM campaigns WHERE id = ?', [$campaign]) === 'pending_review');
check('campagne non diffusée avant validation', call('GET', '/v1/home')['body']['data']['sponsored'] === null || call('GET', '/v1/home')['body']['data']['sponsored']['title'] !== "Promo sim $stamp");
$csrf = webLogin('partenaire@example.com', 'Partenaire-2026!');
call('POST', "/pro/campagnes/$campaign/statut", ['_csrf' => $csrf, 'status' => 'active'], null, [], false, true);
check('validée par l\'agent : bannière sur l\'accueil client', (call('GET', '/v1/home')['body']['data']['sponsored']['title'] ?? '') === "Promo sim $stamp");
call('POST', '/pro/campagnes/' . $campaign . '/statut', ['_csrf' => $csrf, 'status' => 'ended'], null, [], false, true);
call('POST', '/pro/reglages', ['_csrf' => $csrf, 'v' => ['fee_delivery' => '2000']], null, [], false, true);
check('réglage modifié dans le web → appliqué au devis', (call('POST', '/v1/orders/quote', ['lines' => [['product_id' => "prd_$rice", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => '']])['body']['data']['reception_fee'] ?? 0) === 2000);
call('POST', '/pro/reglages', ['_csrf' => $csrf, 'v' => ['fee_delivery' => '1500', 'commission_rate' => '7']], null, [], false, true);
check('commission hors 0/2/3/4 % refusée', Database::value("SELECT v FROM settings WHERE k = 'commission_rate'") === '2');
call('POST', "/pro/commercants/$stallId/suspendre", ['_csrf' => $csrf], null, [], false, true);
check('commerçant suspendu : étal invisible et jeton révoqué', call('GET', "/v1/stalls/stl_$stallId")['status'] === 404 && call('GET', '/v1/me', null, $merchant)['status'] === 401);
call('POST', "/pro/commercants/$stallId/activer", ['_csrf' => $csrf], null, [], false, true);

// ---------------------------------------------------------------------------
section('9. FedaPay sandbox réel (sans passerelle factice)');
$real = curl_init($api . '/v1/orders');
curl_setopt_array($real, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_POSTFIELDS => json_encode(['lines' => [['product_id' => "prd_$rice", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Calavi'], 'payment' => ['operator' => 'moov', 'phone' => '']]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $client, 'Idempotency-Key: sim-real-' . $stamp]]);
$realBody = json_decode((string) curl_exec($real), true)['data'] ?? [];
$provider = (string) \Axi\Core\Config::get('payments.provider');
check("transaction créée chez $provider (sandbox) avec un lien de paiement", ($realBody['payment']['provider'] ?? '') === $provider && str_starts_with((string) ($realBody['payment']['payment_url'] ?? ''), 'http'), json_encode($realBody));
check('statut interrogé : toujours en attente tant que le client n\'a pas payé', (call('POST', '/v1/orders/' . ($realBody['id'] ?? 'cmd_0') . '/verify-payment', null, $client)['body']['data']['status'] ?? '') === 'awaiting_payment');
check('annulation par le client avant paiement', (call('POST', '/v1/orders/' . ($realBody['id'] ?? 'cmd_0') . '/cancel', null, $client)['body']['data']['status'] ?? '') === 'cancelled');

// ---------------------------------------------------------------------------
section('10. Suppression du compte, sécurité, journaux');
$r = call('POST', '/v1/me/delete', ['password' => 'mauvais'], $client);
check('suppression du compte : mot de passe exigé', $r['status'] === 422);
$r = call('POST', '/v1/me/delete', ['password' => 'simulation-123'], $client);
check('compte supprimé : données personnelles effacées, commandes gardées anonymes, jeton révoqué', $r['status'] === 200
    && Database::value('SELECT status FROM users WHERE id = ?', [$clientId]) === 'deleted' && str_ends_with((string) Database::value('SELECT email FROM users WHERE id = ?', [$clientId]), '@deleted.invalid')
    && (int) Database::value('SELECT COUNT(*) FROM orders WHERE client_id = ?', [$clientId]) >= 3 && call('GET', '/v1/me', null, $client)['status'] === 401, $r['raw']);
check('les secrets ne sont jamais servis', !str_contains(call('GET', '/config/config.local.php')['raw'], 'secret_key'));
check('les journaux ne sont jamais servis', !str_contains(call('GET', '/storage/logs/codes.log')['raw'], 'verify_email'));
check('aucune erreur serveur pendant la simulation', !str_contains((string) @file_get_contents(BASE_PATH . '/storage/logs/error.log'), gmdate('Y-m-d H:', time())), 'voir storage/logs/error.log');
@unlink($jar);

echo "\n" . ($failures === 0 ? "RÉSULTAT : OK, $checks vérifications sur $checks passent." : "RÉSULTAT : ÉCHEC, $failures vérification(s) sur $checks en erreur.") . "\n";
exit($failures === 0 ? 0 : 1);
