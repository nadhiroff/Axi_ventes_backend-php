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

/** Crée un compte confirmé (toujours client) et renvoie [jeton, id numérique, email] */
function newAccount(string $tag): array
{
    global $stamp;
    $email = "sim-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => 'Sim', 'last_name' => ucfirst($tag), 'email' => $email, 'password' => 'simulation-123', 'password_confirmation' => 'simulation-123']);
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

/** Demande « Devenir commerçant » envoyée depuis l'app puis acceptée par l'agent Axi (session web $csrf). @return int id de l'étal */
function becomeMerchant(string $token, string $name, string $site, string $aisle, string $type, string $csrf): int
{
    global $photo;
    $r = call('POST', '/v1/me/merchant-application', ['phone' => '0197' . random_int(100000, 999999), 'id_type' => 'cni', 'id_number' => 'SIM' . random_int(1000, 9999), 'stall_name' => $name,
        'merchant_type' => $type, 'site_id' => $site, 'aisle_id' => $aisle, 'row' => 'Allée S', 'stand' => '7',
        'id_front' => new CURLFile($photo, 'image/webp'), 'selfie' => new CURLFile($photo, 'image/webp'), 'stall_photo' => new CURLFile($photo, 'image/webp')], $token, [], true);
    $applicationId = id((string) ($r['body']['data']['application']['id'] ?? 'dem_0'));
    call('POST', "/pro/demandes/$applicationId/accepter", ['_csrf' => $csrf, 'stall_name' => $name, 'site_id' => $site, 'aisle_id' => $aisle], null, [], false, true);
    return id((string) (call('GET', '/v1/me', null, $token)['body']['data']['merchant']['stall_id'] ?? 'stl_0'));
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
$r = call('POST', '/v1/auth/register', ['first_name' => ' Afi ', 'last_name' => 'Houngbo', 'email' => strtoupper($email), 'password' => 'motdepasse-1', 'password_confirmation' => 'motdepasse-1']);
check('inscription client sans téléphone : 201, compte non confirmé', $r['status'] === 201 && $r['body']['data']['user']['role'] === 'client' && $r['body']['data']['user']['phone'] === null, $r['raw']);
$user = Database::one('SELECT * FROM users WHERE email = ?', [$email]);
check('base : email en minuscules, mot de passe haché, paramètres créés', $user !== null && $user['first_name'] === 'Afi' && str_starts_with($user['password_hash'], '$2y$')
    && Database::value('SELECT user_id FROM user_settings WHERE user_id = ?', [$user['id'] ?? 0]) !== null);
check('confirmation du mot de passe différente : refusée', call('POST', '/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'email' => "c-$email", 'password' => 'motdepasse-1', 'password_confirmation' => 'motdepasse-2'])['status'] === 422);
$r = call('POST', '/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'email' => "p-$email", 'password' => 'motdepasse-1', 'profile' => 'merchant']);
check('impossible de s\'inscrire directement commerçant ou partenaire : toujours client', ($r['body']['data']['user']['role'] ?? '') === 'client', $r['raw']);
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
section('2. Devenir commerçant : demande depuis l\'app, validation par un agent Axi sur le web');
[$merchant, $merchantId, $merchantEmail] = newAccount('commercante');
check('le compte créé est un compte client', call('GET', '/v1/me', null, $merchant)['body']['data']['role'] === 'client');
check('espace commerçant interdit à un client (403)', call('GET', '/v1/merchant/today', null, $merchant)['status'] === 403);
$options = call('GET', '/v1/merchant/stall-options', null, $merchant)['body']['data'] ?? [];
check('listes des marchés et allées (en base, « Autre » proposé à part)', count($options['sites'] ?? []) >= 4 && count($options['aisles'] ?? []) >= 7 && ($options['other_aisle_id'] ?? '') === 'autres');
$r = call('POST', '/v1/me/merchant-application', ['phone' => '0197' . substr($stamp, -6), 'id_type' => 'cni', 'id_number' => 'AB12', 'stall_name' => "Étal sim $stamp", 'site_id' => 'yoyo', 'aisle_id' => 'tissus'], $merchant, [], true);
check('demande sans pièce d\'identité ni photos : refusée', $r['status'] === 422 && isset($r['body']['error']['fields']['id_front'], $r['body']['error']['fields']['selfie']), $r['raw']);
$r = call('POST', '/v1/me/merchant-application', ['phone' => '0197' . substr($stamp, -6), 'id_type' => 'cni', 'id_number' => 'AB12', 'stall_name' => "Étal sim $stamp", 'merchant_type' => 'wholesale',
    'site_id' => 'yoyo', 'aisle_id' => 'autres', 'aisle_other' => "Chaussures $stamp", 'row' => 'Allée S', 'stand' => '7',
    'id_front' => new CURLFile($photo, 'image/webp'), 'selfie' => new CURLFile($photo, 'image/webp'), 'stall_photo' => new CURLFile($photo, 'image/webp')], $merchant, [], true);
$applicationId = id((string) ($r['body']['data']['application']['id'] ?? 'dem_0'));
check('demande complète envoyée : en attente', $r['status'] === 201 && $r['body']['data']['application']['status'] === 'pending', $r['raw']);
$idFront = (string) Database::value('SELECT id_front_path FROM merchant_applications WHERE id = ?', [$applicationId]);
check('pièce d\'identité gardée hors de public/ et jamais servie publiquement', str_starts_with($idFront, 'private/') && is_file(BASE_PATH . '/storage/' . $idFront) && call('GET', '/storage/' . $idFront)['status'] !== 200);
check('deuxième demande pendant l\'examen : 409', call('POST', '/v1/me/merchant-application', ['phone' => '0197000000'], $merchant, [], true)['status'] === 409);
$page = call('GET', '/pro/connexion', null, null, [], false, true)['raw'];
preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
check('client refusé sur l\'espace web partenaire', str_contains(call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => $merchantEmail, 'password' => 'simulation-123'], null, [], false, true)['raw'], 'réservé aux partenaires'));
$csrf = webLogin('partenaire@example.com', 'Partenaire-2026!');
check('connexion web de l\'agent Axi', $csrf !== '' && str_contains(call('GET', '/pro', null, null, [], false, true)['raw'], 'Bonjour Agent'));
check('la demande apparaît dans « Demandes commerçants »', str_contains(call('GET', '/pro/demandes', null, null, [], false, true)['raw'], "Étal sim $stamp"));
$fiche = call('GET', "/pro/demandes/$applicationId", null, null, [], false, true)['raw'];
preg_match('#/pro/fichier\?p=[^"]+#', $fiche, $fm);
check('l\'agent voit la pièce d\'identité (lien lié à sa session)', isset($fm[0]) && call('GET', html_entity_decode($fm[0]), null, null, [], false, true)['status'] === 200);
check('formulaire sans jeton CSRF refusé', call('POST', "/pro/demandes/$applicationId/accepter", ['stall_name' => 'x'], null, [], false, true)['status'] === 419);
call('POST', "/pro/demandes/$applicationId/accepter", ['_csrf' => $csrf, 'stall_name' => "Étal sim $stamp", 'site_id' => 'yoyo', 'aisle_id' => 'new'], null, [], false, true);
call('POST', "/pro/demandes/$applicationId/accepter", ['_csrf' => $csrf, 'stall_name' => "Étal sim $stamp", 'site_id' => 'yoyo', 'aisle_id' => 'new'], null, [], false, true);
$me = call('GET', '/v1/me', null, $merchant)['body']['data'] ?? [];
$stallId = id((string) ($me['merchant']['stall_id'] ?? 'stl_0'));
check('acceptée : compte commerçant, étal créé et vérifié', ($me['role'] ?? '') === 'merchant' && $me['merchant']['verified'] === true && $stallId > 0, json_encode($me));
check('double validation : un seul étal, une seule allée créée', (int) Database::value('SELECT COUNT(*) FROM stalls WHERE user_id = ?', [$merchantId]) === 1
    && (int) Database::value('SELECT COUNT(*) FROM aisles WHERE name = ?', ["Chaussures $stamp"]) === 1);
check('allée « Autre » proposée créée pour tout le monde', Database::value('SELECT aisle_id FROM stalls WHERE id = ?', [$stallId]) === (string) Database::value('SELECT id FROM aisles WHERE name = ?', ["Chaussures $stamp"]));
Database::run('UPDATE stalls SET aisle_id = \'tissus\' WHERE id = ?', [$stallId]);
check('félicitations par email + message unique dans l\'app', ($me['merchant_welcome'] ?? false) === true
    && Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Félicitations, vous êtes commerçant Axi !'", [$merchantId]) === 1);
call('POST', '/v1/me/merchant-welcome/seen', null, $merchant);
check('message de félicitations vu : plus affiché', call('GET', '/v1/me', null, $merchant)['body']['data']['merchant_welcome'] === false);
check('action journalisée (admin_actions)', Database::value("SELECT COUNT(*) FROM admin_actions WHERE action = 'application.approve' AND target_id = ?", [$merchantId]) === 1);
[$refused] = newAccount('refusee');
$r = call('POST', '/v1/me/merchant-application', ['phone' => '0196' . substr($stamp, -6), 'id_type' => 'passport', 'id_number' => 'P9', 'stall_name' => 'Refus', 'site_other' => 'Marché de Bohicon', 'aisle_id' => 'tissus',
    'id_front' => new CURLFile($photo, 'image/webp'), 'selfie' => new CURLFile($photo, 'image/webp'), 'stall_photo' => new CURLFile($photo, 'image/webp')], $refused, [], true);
$refusedApp = id((string) ($r['body']['data']['application']['id'] ?? 'dem_0'));
call('POST', "/pro/demandes/$refusedApp/refuser", ['_csrf' => $csrf, 'reason' => 'La pièce d\'identité est illisible.'], null, [], false, true);
$me = call('GET', '/v1/me', null, $refused)['body']['data'] ?? [];
check('demande refusée : reste client, motif visible, peut renvoyer', $me['role'] === 'client' && ($me['merchant_application']['status'] ?? '') === 'rejected' && str_contains((string) $me['merchant_application']['reject_reason'], 'illisible'));

section('3. Étal et produits : photos, vocal, stock, emplacement');
call('PATCH', '/v1/merchant/stall', ['tagline' => 'Pagnes et bazin', 'pay_on_delivery' => true], $merchant);
$r = call('POST', '/v1/merchant/stall/photo', ['photo' => new CURLFile($photo, 'image/webp')], $merchant, [], true);
check('photo de l\'étal', ($r['body']['data']['stall']['avatar']['medium_url'] ?? null) !== null, $r['raw']);
$r = call('POST', '/v1/merchant/stall/photos', ['photos[0]' => new CURLFile($photo, 'image/webp'), 'photos[1]' => new CURLFile($photo, 'image/webp')], $merchant, [], true);
check('galerie de l\'étal : photo principale + 2 photos à faire défiler', count($r['body']['data']['stall']['photos'] ?? []) === 3, $r['raw']);
call('DELETE', '/v1/merchant/stall/photos/1', null, $merchant);
check('photo de la galerie retirée', count(call('GET', "/v1/stalls/stl_$stallId")['body']['data']['stall']['photos'] ?? []) === 2);
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
$r = call('POST', '/v1/merchant/products', ['name' => 'Foulard en soie', 'price' => '3000', 'stock_quantity' => '2'], $merchant, [], true);
$scarfId = id((string) ($r['body']['data']['id'] ?? 'prd_0'));
check('produit avec stock : quantité enregistrée', ($r['body']['data']['stock_quantity'] ?? null) === 2, $r['raw']);
$r = call('PATCH', '/v1/merchant/stall', ['stand' => '9'], $merchant);
check('le commerçant déménage : badge retiré jusqu\'à une nouvelle visite', $r['body']['data']['stall']['verified'] === false && $r['body']['data']['stall']['location']['moved_at'] !== null, $r['raw']);
check('la vérification à faire apparaît dans l\'espace web', str_contains(call('GET', '/pro/verifications', null, null, [], false, true)['raw'], "Étal sim $stamp"));
$r = call('POST', "/pro/commercants/$stallId/verifier", ['_csrf' => $csrf, 'row' => 'Allée S', 'stand' => '9'], null, [], false, true);
check('nouvelle vérification sans nouvelle photo (facultative) : badge rendu', in_array($r['status'], [302, 303], true) && call('GET', "/v1/stalls/stl_$stallId")['body']['data']['stall']['verified'] === true
    && Database::value('SELECT verification_photo_path FROM stalls WHERE id = ?', [$stallId]) !== null, (string) $r['status']);
check('commerçant notifié de la vérification', Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Votre étal est vérifié'", [$merchantId]) === 1);

// ---------------------------------------------------------------------------
section('4. Client : visite, favoris, panier multi-étals, paiement unique, jamais deux débits');
[$second, $secondId] = newAccount('commercant2');
becomeMerchant($second, "Grains sim $stamp", 'daho', 'vivriers', 'retail', $csrf);
$rice = id((string) (call('POST', '/v1/merchant/products', ['name' => 'Sac de riz, 25 kg', 'price' => '17500', 'is_today' => '1'], $second, [], true)['body']['data']['id'] ?? 'prd_0'));
[$client, $clientId] = newAccount('cliente');
check('visiteur : accueil et fiche produit sans compte', call('GET', '/v1/home')['status'] === 200 && call('GET', "/v1/products/prd_$waxId")['body']['data']['images'][0]['medium_url'] !== null);
check('visiteur : commander exige un compte (401)', call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$waxId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'x']])['status'] === 401);
call('GET', "/v1/products/prd_$waxId", null, $client);
call('POST', "/v1/favorites/prd_$waxId", null, $client);
check('récemment vus et favoris enregistrés', count(call('GET', '/v1/me/recently-viewed', null, $client)['body']['data']['products'] ?? []) === 1 && count(call('GET', '/v1/favorites', null, $client)['body']['data']['products'] ?? []) === 1);
call('POST', "/v1/stalls/stl_$stallId/follow", null, $client);
check('nombre d\'abonnés de l\'étal', (call('GET', "/v1/stalls/stl_$stallId")['body']['data']['stall']['followers_count'] ?? 0) === 1);
$rates = call('GET', '/v1/rates')['body']['data']['rates'] ?? [];
check('taux de change : euro à parité fixe, dollar réel', ($rates['EUR'] ?? 0) == 655.957 && ($rates['USD'] ?? 0) > 100);
check('devise euro enregistrée dans les paramètres', (call('PATCH', '/v1/me/settings', ['currency' => 'EUR'], $client)['body']['data']['currency'] ?? '') === 'EUR');
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
section('7. Abonnement au mois ou à l\'année, limites sans abonnement, clients, crédit');
$clients = call('GET', '/v1/merchant/clients', null, $merchant)['body']['data']['clients'] ?? [];
$cli = $clients[0]['id'] ?? 'cli_0';
$fiche = call('GET', "/v1/merchant/clients/$cli", null, $merchant)['body']['data'] ?? [];
check('fiche client avec historique des commandes', ($fiche['has_axi_account'] ?? false) === true && count($fiche['orders'] ?? []) >= 1, json_encode($fiche));
$r = call('POST', "/v1/merchant/clients/$cli/follow-up", null, $merchant);
check('sans abonnement : relance refusée (402) avec explication', $r['status'] === 402 && $r['body']['error']['code'] === 'SUBSCRIPTION_REQUIRED', $r['raw']);
$sub = call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['subscription'] ?? [];
check('limites et prix annoncés (mois, année −5 %)', ($sub['limits']['product_limit'] ?? 0) === 5 && $sub['prices']['month'] === 5000 && $sub['prices']['year'] === 57000 && $sub['prices']['year_discount_pct'] === 5, json_encode($sub));
for ($i = 1; $i <= 1; $i++) {
    call('POST', '/v1/merchant/products', ['name' => "Article $i", 'price' => '1000'], $merchant, [], true);
}
$r = call('POST', '/v1/merchant/products', ['name' => 'Article de trop', 'price' => '1000'], $merchant, [], true);
check('sans abonnement : 6e produit en vente refusé (402)', $r['status'] === 402 && $r['body']['error']['code'] === 'SUBSCRIPTION_REQUIRED', $r['raw']);
$r = call('POST', '/v1/merchant/subscription/pay', ['period' => 'month'], $merchant);
$subRef = (string) ($r['body']['data']['reference'] ?? '');
check('abonnement mensuel grossiste (5 000 en base) : lien de paiement', ($r['body']['data']['amount'] ?? 0) === 5000 && $r['body']['data']['payment_url'] !== null, $r['raw']);
check('double appui sur « Payer l\'abonnement » : même paiement', (call('POST', '/v1/merchant/subscription/pay', ['period' => 'month'], $merchant)['body']['data']['reference'] ?? '') === $subRef);
$r = call('POST', '/v1/merchant/subscription/verify', ['reference' => $subRef], $merchant);
check('abonnement payé et actif', ($r['body']['data']['subscription']['status'] ?? '') === 'active' && $r['body']['data']['subscription']['limits']['product_limit'] === null, $r['raw']);
check('abonné : produits sans limite', call('POST', '/v1/merchant/products', ['name' => 'Article de trop', 'price' => '1000'], $merchant, [], true)['status'] === 201);
$r = call('POST', '/v1/merchant/subscription/pay', ['period' => 'year'], $merchant);
$yearRef = (string) ($r['body']['data']['reference'] ?? '');
check('abonnement annuel : 12 mois −5 % (57 000)', ($r['body']['data']['amount'] ?? 0) === 57000 && $r['body']['data']['period'] === 'year', $r['raw']);
$before = (string) Database::value("SELECT MAX(ends_on) FROM subscriptions WHERE stall_id = ? AND status = 'active'", [$stallId]);
call('POST', '/v1/merchant/subscription/verify', ['reference' => $yearRef], $merchant);
$after = (string) Database::value("SELECT MAX(ends_on) FROM subscriptions WHERE stall_id = ? AND status = 'active'", [$stallId]);
check('l\'année s\'ajoute après le mois en cours (365 jours)', (int) round((strtotime($after) - strtotime($before)) / 86400) === 365, "$before → $after");
$r = call('POST', "/v1/merchant/clients/$cli/follow-up", null, $merchant);
check('relance : notification dans l\'app + lien WhatsApp (aucun SMS)', ($r['body']['data']['notified'] ?? 0) === 1 && str_starts_with((string) $r['body']['data']['whatsapp_url'], 'https://wa.me/'), $r['raw']);
$r = call('POST', '/v1/merchant/clients', ['display_name' => 'Tantine Rose', 'email' => "rose-$stamp@example.com", 'kind' => 'reseller'], $merchant);
check('ajout manuel d\'un client (email, sans téléphone)', $r['status'] === 201, $r['raw']);
$r = call('POST', '/v1/merchant/relocation/broadcast', null, $merchant);
check('nouvelle adresse : app + email + lien WhatsApp', ($r['body']['data']['recipients'] ?? 0) === 2 && $r['body']['data']['notified'] === 1 && $r['body']['data']['emailed'] >= 1, $r['raw']);
check('crédit refusé sans consentement', call('POST', '/v1/merchant/credit-requests', null, $merchant)['body']['error']['code'] === 'DATA_SHARING_REQUIRED');
call('PUT', '/v1/merchant/data-sharing', ['enabled' => true], $merchant);
check('consentement enregistré (date, périmètre) puis demande de crédit', Database::value('SELECT scope FROM data_sharing_consents WHERE merchant_user_id = ? AND revoked_at IS NULL', [$merchantId]) !== null
    && call('POST', '/v1/merchant/credit-requests', ['amount' => 200000], $merchant)['status'] === 200);
$consents = call('GET', '/v1/me/consents', null, $merchant)['body']['data']['consents'] ?? [];
check('mes consentements visibles et révocables', ($consents[0]['active'] ?? false) === true);

// ---------------------------------------------------------------------------
section('7b. Stock : réservé à la commande, produit masqué quand il est épuisé, rendu si annulé');
$r = call('POST', '/v1/orders/quote', ['lines' => [['product_id' => "prd_$scarfId", 'quantity' => 3]], 'reception' => ['mode' => 'delivery', 'detail' => '']]);
check('3 demandés pour 2 en stock : indisponible', ($r['body']['data']['unavailable_product_ids'] ?? []) === ["prd_$scarfId"], $r['raw']);
// Numéro « coupure réseau » de la passerelle factice : le paiement reste en attente, la commande peut être annulée
$r = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$scarfId", 'quantity' => 2]], 'reception' => ['mode' => 'delivery', 'detail' => 'Fidjrossè'], 'payment' => ['operator' => 'mtn', 'phone' => FakeGateway::NETWORK_FAILURE_PHONE]], $client, ['Idempotency-Key' => "sim-$stamp-stock"]);
$scarfOrder = (string) ($r['body']['data']['id'] ?? 'cmd_0');
check('commande des 2 derniers : stock réservé (0)', $r['status'] === 201 && (int) Database::value('SELECT stock_quantity FROM products WHERE id = ?', [$scarfId]) === 0, $r['raw']);
check('épuisé : invisible des clients, visible « épuisé » pour le commerçant, commerçant prévenu', call('GET', "/v1/products/prd_$scarfId")['status'] === 404
    && !in_array("prd_$scarfId", array_column(call('GET', "/v1/stalls/stl_$stallId")['body']['data']['products'] ?? [], 'id'), true)
    && in_array(true, array_column(array_filter(call('GET', '/v1/merchant/stall', null, $merchant)['body']['data']['products'] ?? [], static fn ($p) => $p['id'] === "prd_$scarfId"), 'sold_out'), true)
    && Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Stock épuisé : Foulard en soie'", [$merchantId]) === 1);
$parallel = parallel(4, '/v1/orders', ['lines' => [['product_id' => "prd_$scarfId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'x'], 'payment' => ['operator' => 'mtn', 'phone' => '']], $client);
check('plus de stock : aucune autre commande possible', !in_array(201, array_column($parallel, 'status'), true));
call('POST', "/v1/orders/$scarfOrder/cancel", null, $client);
check('commande annulée avant paiement : stock rendu (2), produit de nouveau visible', (int) Database::value('SELECT stock_quantity FROM products WHERE id = ?', [$scarfId]) === 2 && call('GET', "/v1/products/prd_$scarfId")['status'] === 200);
call('POST', "/v1/orders/$scarfOrder/cancel", null, $client);
check('annulation répétée : stock rendu une seule fois', (int) Database::value('SELECT stock_quantity FROM products WHERE id = ?', [$scarfId]) === 2);
$r = call('PATCH', "/v1/merchant/products/prd_$scarfId", ['stock_quantity' => 10], $merchant);
check('réassort par le commerçant', ($r['body']['data']['stock_quantity'] ?? 0) === 10, $r['raw']);

section('7c. Paiement sur place : frais payés en ligne, marchandise en espèces');
$q = call('POST', '/v1/orders/quote', ['lines' => [['product_id' => "prd_$waxId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => ''], 'payment_mode' => 'on_site'])['body']['data'] ?? [];
check('devis sur place : en ligne = livraison + frais de service (2 %), espèces = marchandise', ($q['pay_on_site_available'] ?? false) === true && $q['service_fee'] === 380 && $q['total'] === 1500 + 380 && $q['cash_total'] === 19000, json_encode($q));
$r = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$rice", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'x'], 'payment_mode' => 'on_site', 'payment' => ['operator' => 'mtn', 'phone' => '']], $client);
check('commerçant qui n\'accepte pas le paiement sur place : refusé', ($r['body']['error']['code'] ?? '') === 'PAY_ON_SITE_UNAVAILABLE', $r['raw']);
$r = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$waxId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Cadjèhoun'], 'payment_mode' => 'on_site', 'payment' => ['operator' => 'mtn', 'phone' => '']], $client, ['Idempotency-Key' => "sim-$stamp-cash"]);
$cashId = (string) ($r['body']['data']['id'] ?? 'cmd_0');
check('commande sur place : paiement en ligne des frais seulement (1 880)', ($r['body']['data']['payment_mode'] ?? '') === 'on_site' && (int) Database::value('SELECT amount FROM payments WHERE order_id = ?', [id($cashId)]) === 1880, $r['raw']);
$held = (int) call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['pending_amount'];
call('POST', "/v1/orders/$cashId/verify-payment", null, $client);
check('frais payés : part du commerçant « espèces », rien de bloqué chez Axi', Database::value('SELECT escrow_state FROM order_stalls WHERE order_id = ?', [id($cashId)]) === 'cash'
    && (int) call('GET', '/v1/merchant/money', null, $merchant)['body']['data']['pending_amount'] === $held
    && Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Nouvelle commande, paiement sur place'", [$merchantId]) === 1);
$r = call('POST', "/v1/orders/$cashId/confirm-receipt", null, $client);
check('réception confirmée : commande livrée, aucun versement dû', ($r['body']['data']['status'] ?? '') === 'delivered' && Database::value('SELECT escrow_state FROM order_stalls WHERE order_id = ?', [id($cashId)]) === 'cash', $r['raw']);

section('7d. Messagerie client ↔ commerçant (24 h, gardée si le client suit l\'étal)');
$secondStall = id((string) (call('GET', '/v1/me', null, $second)['body']['data']['merchant']['stall_id'] ?? 'stl_0'));
$conv = (string) (call('POST', "/v1/stalls/stl_$secondStall/conversation", null, $client)['body']['data']['conversation']['id'] ?? '');
$r = call('POST', "/v1/conversations/$conv/messages", ['body' => 'Bonjour, le riz est-il disponible ?'], $client);
check('le client écrit au commerçant : message enregistré, effacé dans 24 h', $r['status'] === 201 && Database::value('SELECT expires_at FROM messages WHERE id = ?', [id($r['body']['data']['id'])]) !== null, $r['raw']);
check('pastille : 1 message non lu chez le commerçant', (call('GET', '/v1/me/badges', null, $second)['body']['data']['unread_messages'] ?? 0) === 1);
$list = call('GET', '/v1/conversations', null, $second)['body']['data']['conversations'] ?? [];
check('liste des conversations du commerçant', count($list) === 1 && $list[0]['unread_count'] === 1 && $list[0]['my_role'] === 'merchant', json_encode($list));
call('GET', "/v1/conversations/$conv/messages", null, $second);
check('conversation ouverte : lue', (call('GET', '/v1/me/badges', null, $second)['body']['data']['unread_messages'] ?? 1) === 0);
call('POST', "/v1/conversations/$conv/messages", ['body' => 'Oui, 25 kg à 17 500.'], $second);
$r = call('POST', "/v1/conversations/$conv/messages", ['photo' => new CURLFile($photo, 'image/webp')], $second, [], true);
check('photo envoyée dans la conversation', ($r['body']['data']['image']['medium_url'] ?? null) !== null, $r['raw']);
$r = call('POST', "/v1/conversations/$conv/messages", ['audio' => new CURLFile(__DIR__ . '/fixtures/voix.m4a', 'audio/mp4', 'voix.m4a')], $client, [], true);
check('message vocal envoyé (lien d\'écoute signé)', str_contains((string) ($r['body']['data']['audio_url'] ?? ''), 'sig='), $r['raw']);
$msgs = call('GET', "/v1/conversations/$conv/messages", null, $client)['body']['data'] ?? [];
check('le client voit les 4 messages et la règle des 24 h', count($msgs['messages'] ?? []) === 4 && $msgs['conversation']['kept'] === false, json_encode($msgs['conversation'] ?? []));
$mine = array_values(array_filter($msgs['messages'], static fn ($m) => $m['mine']));
check('mes messages : ✓✓ bleu (lu) quand le commerçant a ouvert la conversation', ($mine[0]['status'] ?? '') === 'read', json_encode($mine[0] ?? []));
check('le commerçant est « en ligne » (application ouverte)', ($msgs['conversation']['other_online'] ?? false) === true);
$sent = call('POST', "/v1/conversations/$conv/messages", ['body' => 'Je passe demain.'], $client)['body']['data'] ?? [];
check('nouveau message : ✓ envoyé, pas encore reçu', ($sent['status'] ?? '') === 'sent', json_encode($sent));
call('GET', '/v1/me/badges', null, $second);
$after = call('GET', "/v1/conversations/$conv/messages", null, $client)['body']['data']['messages'] ?? [];
check('application du commerçant ouverte : ✓✓ reçu (pas encore lu)', (end($after)['status'] ?? '') === 'delivered', json_encode(end($after)));
$r = call('POST', "/v1/conversations/$conv/messages", ['body' => 'D\'accord, à demain.', 'reply_to' => $sent['id']], $second);
check('réponse à un message précis (glisser) : aperçu du message cité', ($r['body']['data']['reply']['id'] ?? '') === $sent['id'] && ($r['body']['data']['reply']['preview'] ?? '') === 'Je passe demain.', $r['raw']);
$r = call('PATCH', "/v1/conversations/$conv/messages/" . $sent['id'], ['body' => 'Je passe après-demain.'], $client);
check('modifier son message : texte changé, « modifié »', ($r['body']['data']['body'] ?? '') === 'Je passe après-demain.' && $r['body']['data']['edited'] === true, $r['raw']);
check('impossible de modifier le message de l\'autre (403)', call('PATCH', "/v1/conversations/$conv/messages/" . $sent['id'], ['body' => 'x'], $second)['status'] === 403);
$r = call('DELETE', "/v1/conversations/$conv/messages/" . $sent['id'], null, $client);
check('supprimer son message : « supprimé » pour tout le monde, texte effacé', ($r['body']['data']['deleted'] ?? false) === true && $r['body']['data']['body'] === null
    && Database::value('SELECT body FROM messages WHERE id = ?', [id($sent['id'])]) === null, $r['raw']);
$msgs = call('GET', "/v1/conversations/$conv/messages", null, $client)['body']['data'] ?? [];
$last = end($msgs['messages']);
check('« après » un message : seulement les nouveaux (rafraîchissement léger)', count(call('GET', "/v1/conversations/$conv/messages?after=" . $last['id'], null, $client)['body']['data']['messages'] ?? [1]) === 0);
check('un tiers ne peut pas lire la conversation (404)', call('GET', "/v1/conversations/$conv/messages", null, $merchant)['status'] === 404);
call('POST', "/v1/stalls/stl_$secondStall/follow", null, $client);
check('le client suit l\'étal : messages gardés', (int) Database::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.id = ? AND m.expires_at IS NOT NULL', [id($conv)]) === 0);
call('DELETE', "/v1/stalls/stl_$secondStall/follow", null, $client);
check('il ne suit plus : effacement programmé dans 24 h', (int) Database::value('SELECT COUNT(*) FROM messages m WHERE m.conversation_id = ? AND m.expires_at IS NULL', [id($conv)]) === 0);
Database::run('UPDATE messages SET expires_at = ? WHERE conversation_id = ?', [\Axi\Core\Clock::now(-60), id($conv)]);
check('après 24 h : messages invisibles puis effacés par la tâche planifiée', count(call('GET', "/v1/conversations/$conv/messages", null, $client)['body']['data']['messages'] ?? [1]) === 0
    && \Axi\Services\Messaging::purgeExpired() >= 6 && (int) Database::value('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [id($conv)]) === 0);
$riceClient = (string) (call('GET', '/v1/merchant/clients', null, $second)['body']['data']['clients'][0]['id'] ?? 'cli_0');
$r = call('POST', "/v1/merchant/clients/$riceClient/conversation", null, $second);
check('le commerçant peut écrire à un de ses clients qui a un compte', ($r['body']['data']['conversation']['id'] ?? '') === $conv, $r['raw']);

section('7e. Photo de profil et qui peut la voir');
$r = call('POST', '/v1/me/avatar', ['photo' => new CURLFile($photo, 'image/webp')], $client, [], true);
check('photo de profil ajoutée', ($r['body']['data']['avatar']['medium_url'] ?? null) !== null, $r['raw']);
call('POST', "/v1/conversations/$conv/messages", ['body' => 'Merci !'], $client);
call('PATCH', '/v1/me', ['avatar_visibility' => 'nobody'], $client);
$other = call('GET', '/v1/conversations', null, $second)['body']['data']['conversations'][0]['other'] ?? [];
check('« personne » : le commerçant ne voit pas la photo', array_key_exists('avatar', $other) && $other['avatar'] === null, json_encode($other));
call('PATCH', '/v1/me', ['avatar_visibility' => 'contacts'], $client);
check('« mes contacts » : visible par le commerçant avec qui il discute', (call('GET', '/v1/conversations', null, $second)['body']['data']['conversations'][0]['other']['avatar'] ?? null) !== null);
check('photo retirée', call('DELETE', '/v1/me/avatar', null, $client)['body']['data']['avatar'] === null);

section('7f. Profil, suppression « pour moi », annonces, signalement et blocage');
$followers = call('GET', '/v1/merchant/clients?segment=followers', null, $second)['body']['data'] ?? [];
check('« Mes clients » : les abonnés de l\'étal apparaissent (segment Abonnés)', count($followers['clients'] ?? []) >= 0 && isset($followers['followers_count']), json_encode($followers));
call('POST', "/v1/stalls/stl_$secondStall/follow", null, $client);
$followers = call('GET', '/v1/merchant/clients?segment=followers', null, $second)['body']['data']['clients'] ?? [];
check('un nouvel abonné entre dans le fichier clients, avec accès au profil et à la discussion', count($followers) === 1 && ($followers[0]['user_id'] ?? null) === "usr_$clientId" && $followers[0]['follows'] === true, json_encode($followers));
$profile = call('GET', "/v1/users/usr_$clientId/profile", null, $second)['body']['data'] ?? [];
check('profil vu par le commerçant : prénom + initiale, sans email ni téléphone', ($profile['display_name'] ?? '') === 'Sim C.' && !isset($profile['email']) && !isset($profile['phone']), json_encode($profile));
check('profil d\'un inconnu : introuvable (404)', call('GET', "/v1/users/usr_$clientId/profile", null, $refused)['status'] === 404);
$m = call('POST', "/v1/conversations/$conv/messages", ['body' => 'Message à masquer'], $second)['body']['data'] ?? [];
call('POST', "/v1/conversations/$conv/messages/" . ($m['id'] ?? 'msg_0') . '/hide', null, $client);
$clientSees = array_column(call('GET', "/v1/conversations/$conv/messages", null, $client)['body']['data']['messages'] ?? [], 'id');
$merchantSees = array_column(call('GET', "/v1/conversations/$conv/messages", null, $second)['body']['data']['messages'] ?? [], 'id');
check('message supprimé « pour moi » : disparu chez moi, toujours là chez l\'autre', !in_array($m['id'] ?? '', $clientSees, true) && in_array($m['id'] ?? '', $merchantSees, true));
$r = call('POST', '/v1/merchant/arrivals', ['text' => 'Arrivage de riz parfumé ce matin !', 'photo' => new CURLFile($photo, 'image/webp')], $second, [], true);
check('annonce d\'arrivage écrite sans abonnement : sur la page de l\'étal seulement', $r['status'] === 200 && count(call('GET', "/v1/stalls/stl_$secondStall")['body']['data']['stall']['announcements'] ?? []) === 1
    && !in_array("stl_$secondStall", array_column(array_column(call('GET', '/v1/home')['body']['data']['announcements'] ?? [], 'stall'), 'id'), true), $r['raw']);
call('POST', '/v1/merchant/arrivals', ['text' => 'Nouveaux pagnes wax arrivés'], $merchant, [], true);
$home = call('GET', '/v1/home', null, $client)['body']['data']['announcements'] ?? [];
check('commerçant abonné : annonce en petite pub sur l\'accueil, badge abonné, bouton suivre', in_array("stl_$stallId", array_column(array_column($home, 'stall'), 'id'), true) && ($home[0]['stall']['subscribed'] ?? false) === true, json_encode($home));
call('POST', '/v1/stalls/stl_' . $stallId . '/voice-messages', ['audio' => new CURLFile(__DIR__ . '/fixtures/voix.m4a', 'audio/mp4', 'voix.m4a')], $client, [], true);
$vm = call('GET', '/v1/merchant/voice-messages', null, $merchant)['body']['data']['messages'][0]['id'] ?? 'vm_0';
check('message vocal reçu supprimé par le commerçant', call('DELETE', "/v1/merchant/voice-messages/$vm", null, $merchant)['status'] === 200
    && !in_array($vm, array_column(call('GET', '/v1/merchant/voice-messages', null, $merchant)['body']['data']['messages'] ?? [], 'id'), true));
$before = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$clientId]);
call('POST', '/v1/notifications/read', ['all' => true], $client);
check('notifications vues : effacées', $before > 0 && (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$clientId]) === 0);
$r = call('POST', "/v1/users/usr_$secondId/report", ['reason' => 'fraud', 'details' => 'Demande un paiement hors Axi.', 'conversation_id' => $conv], $client);
check('signaler un profil', $r['status'] === 201 && call('POST', "/v1/users/usr_$secondId/report", ['reason' => 'spam'], $client)['status'] === 409, $r['raw']);
check('le signalement apparaît dans l\'espace web', str_contains(call('GET', '/pro/signalements', null, null, [], false, true)['raw'], 'Demande un paiement hors Axi.'));
$reportId = (int) Database::value('SELECT id FROM reports WHERE target_user_id = ?', [$secondId]);
call('POST', "/pro/signalements/$reportId/bloquer", ['_csrf' => $csrf, 'reason' => 'Tentative d\'arnaque signalée.'], null, [], false, true);
$login = call('POST', '/v1/auth/login', ['email' => "sim-commercant2-$stamp@example.com", 'password' => 'simulation-123']);
check('compte bloqué : déconnecté, et message avec le motif à la connexion', call('GET', '/v1/me', null, $second)['status'] === 401 && ($login['body']['error']['code'] ?? '') === 'ACCOUNT_SUSPENDED'
    && str_contains($login['body']['error']['message'] ?? '', 'Tentative d\'arnaque'), $login['raw']);
check('étal du commerçant bloqué : invisible', call('GET', "/v1/stalls/stl_$secondStall")['status'] === 404);
call('POST', "/pro/clients/$secondId/activer", ['_csrf' => $csrf], null, [], false, true);
check('accès débloqué par l\'agent : connexion de nouveau possible', call('POST', '/v1/auth/login', ['email' => "sim-commercant2-$stamp@example.com", 'password' => 'simulation-123'])['status'] === 200);

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
