<?php
declare(strict_types=1);

// Parcours complet d'une annonce contre le serveur local : création, relecture, affichage selon le public,
// signalement, correction directe par l'équipe Axi. Tout ce qui est créé est effacé à la fin.
// Usage : php tests/ads_flow.php [URL]   (défaut http://127.0.0.1:8000)

use Axi\Core\Auth;
use Axi\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$fails = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$fails): void {
    echo($ok ? '  OK    ' : '  ÉCHEC ') . $label . ($ok ? '' : "  → $detail") . "\n";
    $fails += $ok ? 0 : 1;
};
$session = static function (string $email) use ($api): array {
    $jar = (string) tempnam(sys_get_temp_dir(), 'axi-ad');
    $web = static function (string $path, ?array $post = null, array $file = []) use ($api, $jar): array {
        $ch = curl_init($api . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $file === [] ? http_build_query($post) : $post + $file);
        }
        $html = (string) curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $html];
    };
    [, $page] = $web('/pro/connexion');
    preg_match('/name="_csrf" value="([^"]*)"/', $page, $m);
    $web('/pro/connexion', ['_csrf' => $m[1], 'email' => $email, 'password' => 'Partenaire-2026!']);
    return [$web, $jar];
};
$csrf = static function (string $html): string {
    preg_match('/name="_csrf" value="([^"]*)"/', $html, $m);
    return $m[1] ?? '';
};
$json = static function (string $method, string $path, ?string $token = null, ?array $body = null) use ($api): array {
    $ch = curl_init($api . $path);
    $h = ['Accept: application/json'] + [];
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

[$ad, $jarA] = $session('annonceur@example.com');
[$agent, $jarB] = $session('partenaire@example.com');
$merchantId = (int) Database::value("SELECT s.user_id FROM stalls s JOIN users u ON u.id = s.user_id WHERE u.status = 'active' AND s.status = 'active' ORDER BY s.id LIMIT 1");
$token = Auth::issueToken($merchantId, 'ads-flow-test');
$clientAd = static fn (): ?array => $json('GET', '/v1/home')[1]['data']['ads'][0] ?? null;
$merchantAd = static fn (): ?array => $json('GET', '/v1/merchant/today', $token)[1]['data']['ads'][0] ?? null;
$ids = [];
$liveV = static fn (): string => (string) ($json('GET', '/v1/live')[1]['data']['v'] ?? '');
// Les annonces en ligne sont mises de côté pendant le test
$live = array_column(Database::all("SELECT id FROM campaigns WHERE status = 'active'"), 'id');
if ($live !== []) {
    Database::run("UPDATE campaigns SET status = 'ended' WHERE id IN (" . Database::in($live) . ')', $live);
}
$image = sys_get_temp_dir() . '/axi-ad-test.jpg';
$g = imagecreatetruecolor(600, 300);
imagefill($g, 0, 0, imagecolorallocate($g, 194, 65, 12));
imagejpeg($g, $image);
$id = 0;

try {
    echo "1. Création par l'annonceur\n";
    [, $page] = $ad('/pro/campagnes');
    $check('formulaire court : plus de format, d\'allée ni d\'étal', !str_contains($page, 'name="format"') && !str_contains($page, 'name="aisle_id"') && !str_contains($page, 'name="target_stall_id"') && str_contains($page, 'name="audience_type"'));
    $form = ['_csrf' => $csrf($page), 'title' => 'Essai annonce', 'body' => '', 'audience_type' => 'merchants', 'budget' => '60000', 'url' => 'https://example.com/promo'];
    $ad('/pro/campagnes', $form);
    $check('sans description : refusée', (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE title = 'Essai annonce'") === 0);
    [, $page] = $ad('/pro/campagnes');
    $ad('/pro/campagnes', ['_csrf' => $csrf($page), 'body' => 'Grande promo'] + $form, ['media' => new CURLFile($image, 'image/jpeg')]);
    $id = (int) Database::value("SELECT MAX(id) FROM campaigns WHERE title = 'Essai annonce'");
    $row = Database::one('SELECT * FROM campaigns WHERE id = ?', [$id]);
    $check('annonce créée, image enregistrée, en relecture', $id > 0 && $row['image_path'] !== null && $row['status'] === 'pending_review', json_encode($row));

    echo "\n2. Publication selon le public\n";
    $check('invisible avant validation', $merchantAd() === null);
    [, $page] = $agent('/pro/campagnes');
    $agent("/pro/campagnes/$id/statut", ['_csrf' => $csrf($page), 'status' => 'active']);
    $m = $merchantAd();
    $check('commerçants : visible, étiquette Annonceur, lien gardé', ($m['title'] ?? '') === 'Essai annonce' && $m['label'] === 'Annonceur' && $m['link_url'] === 'https://example.com/promo', json_encode($m));
    $check('clients : pas visible (réservée aux commerçants)', $clientAd() === null);
    Database::run("UPDATE campaigns SET audience_type = 'all' WHERE id = ?", [$id]);
    $check('public « Tous » : visible des deux côtés', ($clientAd()['title'] ?? '') === 'Essai annonce' && ($merchantAd()['title'] ?? '') === 'Essai annonce');

    echo "\n3. Compteurs et signalement\n";
    $spn = $m['id'];
    $json('POST', "/v1/promotions/$spn/view");
    $json('POST', "/v1/promotions/$spn/click");
    $row = Database::one('SELECT impressions, clicks FROM campaigns WHERE id = ?', [$id]);
    $check('un affichage et un clic comptés', (int) $row['impressions'] === 1 && (int) $row['clicks'] === 1, json_encode($row));
    $check('signaler sans compte : refusé', $json('POST', "/v1/promotions/$spn/report", null, ['reason' => 'scam'])[0] === 401);
    $check('motif inconnu : refusé', $json('POST', "/v1/promotions/$spn/report", $token, ['reason' => 'xx'])[0] === 422);
    $check('signalement envoyé', $json('POST', "/v1/promotions/$spn/report", $token, ['reason' => 'scam'])[0] === 200);
    $json('POST', "/v1/promotions/$spn/report", $token, ['reason' => 'other']);
    $check('une seule fois par personne', (int) Database::value('SELECT COUNT(*) FROM campaign_reports WHERE campaign_id = ?', [$id]) === 1);

    echo "\n4. L'équipe Axi voit et corrige\n";
    [, $page] = $agent('/pro/signalements');
    $check('visible dans Signalements', str_contains($page, 'Annonces signalées') && str_contains($page, "/pro/campagnes/$id/modifier"));
    [, $page] = $agent("/pro/campagnes/$id/modifier");
    $check('motif visible sur la fiche', str_contains($page, 'Arnaque'));
    $agent("/pro/campagnes/$id/modifier", ['_csrf' => $csrf($page), 'title' => 'Annonce corrigée', 'body' => 'Texte corrigé', 'audience_type' => 'all', 'budget' => '60000', 'url' => '', 'starts_at' => '', 'ends_at' => '']);
    $row = Database::one('SELECT title, status, url, image_path FROM campaigns WHERE id = ?', [$id]);
    $check('corrigée sans passer en relecture, image gardée', $row['title'] === 'Annonce corrigée' && $row['status'] === 'active' && $row['url'] === null && $row['image_path'] !== null, json_encode($row));
    $check('signalements classés', (int) Database::value("SELECT COUNT(*) FROM campaign_reports WHERE campaign_id = ? AND status = 'open'", [$id]) === 0);
    $check('changement visible tout de suite dans l\'app', ($clientAd()['title'] ?? '') === 'Annonce corrigée');
    echo "\n5. Modifier une annonce terminée\n";
    Database::run("UPDATE campaigns SET status = 'ended' WHERE id = ?", [$id]);
    [, $page] = $ad('/pro/campagnes');
    $check('bouton Modifier visible', str_contains($page, "/pro/campagnes/$id/modifier"));
    [, $page] = $ad("/pro/campagnes/$id/modifier");
    $check('dates proposées : aujourd\'hui + 30 jours', str_contains($page, 'value="' . date('Y-m-d') . '"'));
    $form = ['_csrf' => $csrf($page), 'title' => 'Annonce corrigée', 'body' => 'Texte relancé', 'audience_type' => 'all', 'budget' => '60000', 'url' => '', 'starts_at' => '2020-01-01', 'ends_at' => '2020-02-01'];
    $ad("/pro/campagnes/$id/modifier", $form);
    $check('fin passée : refusée', Database::value('SELECT status FROM campaigns WHERE id = ?', [$id]) === 'ended');
    [, $page] = $ad("/pro/campagnes/$id/modifier");
    $ad("/pro/campagnes/$id/modifier", ['_csrf' => $csrf($page), 'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d', strtotime('+10 days'))] + $form);
    $row = Database::one('SELECT status, body FROM campaigns WHERE id = ?', [$id]);
    $check('modifiée : repart en relecture', $row['status'] === 'pending_review' && $row['body'] === 'Texte relancé', json_encode($row));

    echo "\n6. Relancer une annonce terminée\n";
    Database::run("UPDATE campaigns SET status = 'ended' WHERE id = ?", [$id]);
    [, $page] = $ad('/pro/campagnes');
    $check('bouton Relancer visible', str_contains($page, "/pro/campagnes/$id/relancer"));
    $ad("/pro/campagnes/$id/relancer", ['_csrf' => $csrf($page)]);
    $row = Database::one('SELECT status, title, image_path, starts_at, ends_at, impressions FROM campaigns WHERE id = ?', [$id]);
    $check('repart en relecture, texte et image gardés, 30 jours', $row['status'] === 'pending_review' && $row['title'] === 'Annonce corrigée' && $row['image_path'] !== null && strtotime($row['ends_at']) - strtotime($row['starts_at']) >= 29 * 86400, json_encode($row));
    $check('une seule ligne : pas de doublon', (int) Database::value("SELECT COUNT(*) FROM campaigns WHERE title = 'Annonce corrigée'") === 1);
    [, $page] = $ad('/pro/campagnes');
    $ad("/pro/campagnes/$id/relancer", ['_csrf' => $csrf($page)]);
    $check('déjà relancée : deuxième clic sans effet', Database::value('SELECT status FROM campaigns WHERE id = ?', [$id]) === 'pending_review');
    Database::run("UPDATE campaigns SET status = 'ended' WHERE id = ?", [$id]);
    $before = $liveV();
    [, $page] = $agent('/pro/campagnes');
    $agent("/pro/campagnes/$id/relancer", ['_csrf' => $csrf($page)]);
    $check('l\'équipe Axi : remise en ligne directement', Database::value('SELECT status FROM campaigns WHERE id = ?', [$id]) === 'active');

    echo "\n7. Mise à jour automatique de l'application\n";
    $after = $liveV();
    $check('annonce relancée : l\'application le voit', $before !== '' && $after !== $before);
    $json('POST', '/v1/promotions/' . (string) ($clientAd()['id'] ?? '') . '/view');
    $check('une simple vue ne déclenche rien', $liveV() === $after);
    $second = (int) Database::insert(
        "INSERT INTO campaigns (organization_id, created_by, format, audience_type, title, body, budget, status, starts_at, ends_at)
         SELECT organization_id, created_by, format, 'all', 'Deuxième annonce', '', budget, 'active', starts_at, ends_at FROM campaigns WHERE id = ?",
        [$id],
    );
    $ids[] = $second;
    $check('deuxième annonce : l\'application le voit', $liveV() !== $after);
    $check('deux annonces envoyées à l\'application', count($json('GET', '/v1/home')[1]['data']['ads'] ?? []) === 2);
} finally {
    if ($id > 0) {
        Database::run('DELETE FROM campaigns WHERE id = ?', [$id]);
    }
    foreach ($ids as $extra) {
        Database::run('DELETE FROM campaigns WHERE id = ?', [$extra]);
    }
    if ($live !== []) {
        Database::run("UPDATE campaigns SET status = 'active' WHERE id IN (" . Database::in($live) . ')', $live);
    }
    Database::run("DELETE FROM auth_tokens WHERE device = 'ads-flow-test'");
    @unlink($jarA);
    @unlink($jarB);
    @unlink($image);
}
echo $fails === 0 ? "\nTout est bon.\n" : "\n$fails échec(s).\n";
exit($fails === 0 ? 0 : 1);
