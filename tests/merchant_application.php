<?php
declare(strict_types=1);

// Demande « Devenir commerçant » : IFU et RCCM en PDF obligatoires, passeport recto ET verso, carte d'identité
// et CIP une seule photo, documents lisibles seulement par un agent Axi.
// Usage : php tests/merchant_application.php [URL]   (défaut http://localhost:8000, env local, base de test)
// Crée des comptes @example.com et des demandes de test.

use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$stamp = (string) time();
$failures = 0;
$checks = 0;
$tmp = sys_get_temp_dir() . "/axi-demande-$stamp";
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
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $raw = (string) curl_exec($ch);
    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
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

function newClient(string $tag): string
{
    global $stamp;
    $email = "demande-$tag-$stamp@example.com";
    call('POST', '/v1/auth/register', ['first_name' => 'Test', 'last_name' => ucfirst($tag), 'email' => $email, 'password' => 'demande-1234', 'password_confirmation' => 'demande-1234']);
    return (string) (call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email), 'device' => 'test-demande'])['body']['data']['token'] ?? '');
}

// Fichiers de test : une vraie image, un vrai PDF minimal, et un faux « PDF » (texte renommé)
$image = "$tmp/photo.png";
$gd = imagecreatetruecolor(320, 200);
imagefill($gd, 0, 0, imagecolorallocate($gd, 194, 65, 12));
imagepng($gd, $image);
$pdf = "$tmp/ifu.pdf";
file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$fakePdf = "$tmp/faux.pdf";
file_put_contents($fakePdf, "Ceci n'est pas un PDF, seulement du texte renommé en .pdf\n");

$site = (string) Database::value("SELECT id FROM sites WHERE active = 1 AND id <> 'autre' ORDER BY sort_order LIMIT 1");
$aisle = (string) Database::value("SELECT id FROM aisles WHERE active = 1 AND id <> 'autres' ORDER BY sort_order LIMIT 1");

/** Champs d'une demande ; $files : noms des fichiers à joindre */
function application(string $idType, array $files, array $override = []): array
{
    global $image, $pdf, $site, $aisle;
    $body = ['phone' => '0197' . random_int(100000, 999999), 'id_type' => $idType, 'id_number' => 'T' . random_int(10000, 99999), 'stall_name' => 'Étal test demande',
        'merchant_type' => 'retail', 'site_id' => $site, 'aisle_id' => $aisle];
    $sources = ['id_front' => [$image, 'image/png'], 'id_back' => [$image, 'image/png'], 'selfie' => [$image, 'image/png'], 'stall_photo' => [$image, 'image/png'],
        'ifu' => [$pdf, 'application/pdf'], 'rccm' => [$pdf, 'application/pdf']];
    foreach ($files as $name) {
        [$path, $mime] = $override[$name] ?? $sources[$name];
        $body[$name] = new CURLFile($path, $mime, basename($path));
    }
    return $body;
}

section('1. IFU et RCCM obligatoires');
$token = newClient('cni');
check('compte client de test créé', $token !== '');
$r = call('POST', '/v1/me/merchant-application', application('cni', ['id_front', 'selfie', 'stall_photo']), $token, true);
check('sans IFU ni RCCM : refusée (422) avec les deux champs signalés', $r['status'] === 422 && isset($r['body']['error']['fields']['ifu'], $r['body']['error']['fields']['rccm']), $r['raw']);
$r = call('POST', '/v1/me/merchant-application', application('cni', ['id_front', 'selfie', 'stall_photo', 'ifu']), $token, true);
check('avec l\'IFU seul : RCCM réclamé', $r['status'] === 422 && isset($r['body']['error']['fields']['rccm']) && !isset($r['body']['error']['fields']['ifu']), $r['raw']);
$r = call('POST', '/v1/me/merchant-application', application('cni', ['id_front', 'selfie', 'stall_photo', 'ifu', 'rccm'], ['rccm' => [$fakePdf, 'application/pdf']]), $token, true);
check('faux PDF (texte renommé .pdf) : refusé, contenu vérifié', $r['status'] === 422 && ($r['body']['error']['code'] ?? '') === 'INVALID_DOCUMENT', $r['raw']);
check('rien n\'est enregistré tant que la demande est refusée', Database::value('SELECT COUNT(*) FROM merchant_applications a JOIN auth_tokens t ON t.user_id = a.user_id WHERE t.token_hash = ?', [hash('sha256', $token)]) == 0);

section('2. Carte d\'identité : une seule photo de la pièce');
$r = call('POST', '/v1/me/merchant-application', application('cni', ['id_front', 'id_back', 'selfie', 'stall_photo', 'ifu', 'rccm']), $token, true);
check('demande acceptée (201)', $r['status'] === 201, $r['raw']);
$cni = Database::one('SELECT a.* FROM merchant_applications a JOIN auth_tokens t ON t.user_id = a.user_id WHERE t.token_hash = ? ORDER BY a.id DESC LIMIT 1', [hash('sha256', $token)]);
check('un verso envoyé par erreur est ignoré pour une carte d\'identité', $cni !== null && $cni['id_back_path'] === null);
check('IFU et RCCM enregistrés en PDF, hors du dossier public', $cni !== null && str_starts_with((string) $cni['ifu_path'], 'private/applications/') && str_ends_with((string) $cni['rccm_path'], '.pdf')
    && is_file(BASE_PATH . '/storage/' . $cni['ifu_path']) && str_starts_with((string) file_get_contents(BASE_PATH . '/storage/' . $cni['ifu_path'], false, null, 0, 5), '%PDF-'));
$r = call('POST', '/v1/me/merchant-application', application('cni', ['id_front', 'selfie', 'stall_photo', 'ifu', 'rccm']), $token, true);
check('deuxième demande pendant la vérification : refusée (409)', $r['status'] === 409, $r['raw']);

section('3. CIP : une seule photo');
$tokenCip = newClient('cip');
$r = call('POST', '/v1/me/merchant-application', application('cip', ['id_front', 'selfie', 'stall_photo', 'ifu', 'rccm']), $tokenCip, true);
check('CIP sans verso : acceptée', $r['status'] === 201, $r['raw']);

section('4. Passeport : recto ET verso');
$tokenPass = newClient('passeport');
$r = call('POST', '/v1/me/merchant-application', application('passport', ['id_front', 'selfie', 'stall_photo', 'ifu', 'rccm']), $tokenPass, true);
check('passeport sans verso : refusé, verso réclamé', $r['status'] === 422 && isset($r['body']['error']['fields']['id_back']), $r['raw']);
$r = call('POST', '/v1/me/merchant-application', application('passport', ['id_front', 'id_back', 'selfie', 'stall_photo', 'ifu', 'rccm']), $tokenPass, true);
check('passeport recto et verso : acceptée', $r['status'] === 201, $r['raw']);
$pass = Database::one('SELECT a.* FROM merchant_applications a JOIN auth_tokens t ON t.user_id = a.user_id WHERE t.token_hash = ? ORDER BY a.id DESC LIMIT 1', [hash('sha256', $tokenPass)]);
check('les deux faces sont enregistrées', $pass !== null && $pass['id_front_path'] !== null && $pass['id_back_path'] !== null);

section('5. Documents visibles seulement par un agent Axi');
$direct = call('GET', '/storage/' . $cni['ifu_path'], null, null, false, true);
check('le PDF n\'est pas accessible par une adresse directe', $direct['status'] !== 200 || !str_starts_with($direct['raw'], '%PDF-'), 'HTTP ' . $direct['status']);
@unlink($jar);
$page = call('GET', '/pro/connexion', null, null, false, true)['raw'];
preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
call('POST', '/pro/connexion', ['_csrf' => $m[1] ?? '', 'email' => 'partenaire@example.com', 'password' => 'Partenaire-2026!'], null, false, true);
$show = call('GET', '/pro/demandes/' . $cni['id'], null, null, false, true);
check('agent : la demande affiche l\'IFU et le RCCM', $show['status'] === 200 && substr_count($show['raw'], 'Ouvrir le PDF') === 2 && str_contains($show['raw'], 'RCCM'), 'HTTP ' . $show['status']);
check('carte d\'identité : une seule photo de pièce affichée (pas de « verso »)', !str_contains($show['raw'], 'Pièce d&#039;identité (verso)') && !str_contains($show['raw'], "Pièce d'identité (verso)"));
preg_match_all('#href="([^"]*/pro/fichier\?[^"]+)"#', $show['raw'], $links);
$pdfLink = null;
foreach ($links[1] as $href) {
    $href = html_entity_decode($href);
    $path = (string) parse_url($href, PHP_URL_PATH);
    $candidate = substr($path, (int) strpos($path, '/pro/fichier')) . '?' . parse_url($href, PHP_URL_QUERY);
    $file = call('GET', $candidate, null, null, false, true);
    if (str_contains($file['type'], 'application/pdf')) {
        $pdfLink = $candidate;
        check('agent : le PDF s\'ouvre (application/pdf)', $file['status'] === 200 && str_starts_with($file['raw'], '%PDF-'));
        break;
    }
}
check('lien du PDF trouvé dans la page', $pdfLink !== null);
@unlink($jar);
if ($pdfLink !== null) {
    $anon = call('GET', $pdfLink, null, null, false, true);
    check('sans session d\'agent : le lien du PDF ne marche pas', $anon['status'] !== 200 || !str_starts_with($anon['raw'], '%PDF-'), 'HTTP ' . $anon['status']);
}
$passShow = call('GET', '/pro/demandes/' . $pass['id'], null, null, false, true);
check('sans session : la page de la demande est inaccessible', !str_contains($passShow['raw'], 'Ouvrir le PDF'));

section('6. Listes des allées et des marchés pour le nouveau sélecteur');
$options = call('GET', '/v1/merchant/stall-options', null, $token);
check('allées et marchés renvoyés, « Autre » à part', $options['status'] === 200 && is_array($options['body']['data']['aisles'] ?? null) && ($options['body']['data']['other_aisle_id'] ?? '') === 'autres'
    && !in_array('autre', array_column($options['body']['data']['sites'] ?? [], 'id'), true), $options['raw']);

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo "\n$checks vérifications, " . ($failures === 0 ? 'toutes réussies' : "$failures en échec") . "\n";
exit($failures === 0 ? 0 : 1);
