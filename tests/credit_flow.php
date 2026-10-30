<?php
declare(strict_types=1);

// Parcours complet du crédit contre le serveur local : demande, offre, acceptation, versement, remboursements.
// Usage : php tests/credit_flow.php [URL]   (défaut http://127.0.0.1:8000). Tout ce qui est créé est effacé à la fin, même en cas d'échec.

use Axi\Core\Auth;
use Axi\Core\Database;
use Axi\Services\Payment\Escrow;

require dirname(__DIR__) . '/src/bootstrap.php';

$api = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$fails = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$fails): void {
    echo($ok ? '  OK    ' : '  ÉCHEC ') . $label . ($ok ? '' : "  → $detail") . "\n";
    $fails += $ok ? 0 : 1;
};
$call = static function (string $method, string $path, string $token, ?array $body = null) use ($api): array {
    $ch = curl_init($api . $path);
    $headers = ["Authorization: Bearer $token", 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    $raw = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?? []];
};
$jar = tempnam(sys_get_temp_dir(), 'axi-credit');
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
$post = static function (string $path, array $fields) use ($web, $csrf): array {
    [, $page] = $web('/pro/credits');
    return $web($path, $fields + ['_csrf' => $csrf($page)]);
};

// Commerçant sans crédit ni partage, avec de l'argent libéré à verser (pour tester la retenue)
$row = Database::one(
    "SELECT s.id AS stall_id, s.user_id FROM stalls s JOIN users u ON u.id = s.user_id JOIN order_stalls os ON os.stall_id = s.id
     WHERE u.status = 'active' AND s.status = 'active' AND os.escrow_state = 'released' AND os.payout_id IS NULL AND os.net_amount > 0
       AND NOT EXISTS (SELECT 1 FROM credits c WHERE c.merchant_user_id = u.id) AND NOT EXISTS (SELECT 1 FROM data_sharing_consents k WHERE k.merchant_user_id = u.id)
     GROUP BY s.id, s.user_id ORDER BY s.id LIMIT 1",
);
if ($row === null) {
    exit("Aucun commerçant de test disponible (argent libéré, sans crédit).\n");
}
[$uid, $stallId] = [(int) $row['user_id'], (int) $row['stall_id']];
$mfiId = (int) Database::value("SELECT id FROM organizations WHERE type = 'mfi' ORDER BY id LIMIT 1");
$agentId = (int) Database::value("SELECT id FROM users WHERE email = 'partenaire@example.com'");
$maxNotif = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?', [$uid]);
$maxPayout = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM payouts');
$token = Auth::issueToken($uid, 'credit-flow-test');
$money = static fn (): array => $call('GET', '/v1/merchant/money', $token)[1]['data']['credit'];
$creditId = 0;

try {
    echo "Commerçant de test : compte $uid, étal $stallId\n\n1. Demande\n";
    $check('sans partage : demande refusée', $call('POST', '/v1/merchant/credit-requests', $token, ['amount' => 100000])[0] === 422);
    $check('partage activé', $call('PUT', '/v1/merchant/data-sharing', $token, ['enabled' => true])[0] === 200);
    $check('demande envoyée', $call('POST', '/v1/merchant/credit-requests', $token, ['amount' => 100000])[0] === 200);
    $creditId = (int) Database::value('SELECT MAX(id) FROM credits WHERE merchant_user_id = ?', [$uid]);
    $check('deuxième demande refusée', $call('POST', '/v1/merchant/credit-requests', $token, ['amount' => 100000])[0] === 409);
    $check('suivi : demande envoyée', ($money()['last_request']['status'] ?? '') === 'new');

    echo "\n2. Connexion de l'IMF\n";
    [, $login] = $web('/pro/connexion');
    $web('/pro/connexion', ['_csrf' => $csrf($login), 'email' => 'imf@example.com', 'password' => 'Partenaire-2026!']);
    [$code, $page] = $web('/pro/credits');
    $check('l\'IMF voit la demande', $code === 200 && str_contains($page, 'Nouvelle demande'), (string) $code);

    echo "\n3. Offre\n";
    $post("/pro/credits/$creditId", ['status' => 'offered', 'amount' => '100000', 'repay_total' => '90000', 'term_months' => '6', 'withhold_pct' => '20']);
    $check('total inférieur au prêt : refusé', Database::value('SELECT status FROM credits WHERE id = ?', [$creditId]) === 'new');
    $post("/pro/credits/$creditId", ['status' => 'offered', 'amount' => '100000', 'repay_total' => '110000', 'term_months' => '6', 'withhold_pct' => '80']);
    $check('retenue de 80 % : refusée', Database::value('SELECT status FROM credits WHERE id = ?', [$creditId]) === 'new');
    $post("/pro/credits/$creditId/versement", ['disbursed_ref' => 'TROP-TOT']);
    $check('versement avant accord : refusé', Database::value('SELECT status FROM credits WHERE id = ?', [$creditId]) === 'new');
    $post("/pro/credits/$creditId", ['status' => 'offered', 'amount' => '100000', 'repay_total' => '110000', 'term_months' => '6', 'withhold_pct' => '20', 'note' => 'Offre de test']);
    $last = $money()['last_request'] ?? [];
    $check('le commerçant voit l\'offre et ses conditions', ($last['status'] ?? '') === 'offered' && $last['repay_total'] === 110000 && $last['term_months'] === 6 && $last['withhold_pct'] === 20, json_encode($last));

    echo "\n4. Acceptation\n";
    $check('offre acceptée', $call('POST', '/v1/merchant/credit-offer', $token, ['accept' => true])[0] === 200);
    $c = Database::one('SELECT * FROM credits WHERE id = ?', [$creditId]);
    $check('statut « à verser », commission calculée', $c['status'] === 'accepted' && (int) $c['commission'] === 2000, json_encode($c));
    $check('plus d\'offre à accepter', $call('POST', '/v1/merchant/credit-offer', $token, ['accept' => true])[0] === 404);

    echo "\n5. Versement\n";
    $post("/pro/credits/$creditId/versement", ['disbursed_ref' => 'VIR-TEST-001']);
    $c = Database::one('SELECT * FROM credits WHERE id = ?', [$creditId]);
    $check('crédit en remboursement, référence gardée', $c['status'] === 'granted' && $c['disbursed_ref'] === 'VIR-TEST-001', json_encode($c));

    echo "\n6. Remboursement à la main\n";
    $post("/pro/credits/$creditId/remboursement", ['amount' => '10000']);
    $check('10 000 F enregistrés', (int) Database::value('SELECT repaid_amount FROM credits WHERE id = ?', [$creditId]) === 10000);
    $check('reste 100 000 F côté commerçant', ($money()['last_request']['remaining'] ?? 0) === 100000);

    echo "\n7. Retenue automatique sur un versement Axi\n";
    $due = (int) Database::value("SELECT SUM(net_amount) FROM order_stalls WHERE stall_id = ? AND escrow_state = 'released' AND payout_id IS NULL", [$stallId]);
    $result = Escrow::payout($stallId, $agentId);
    $held = min(100000, (int) floor($due * 0.2));
    $check("versement de $due F : " . ($due - $held) . " au commerçant, $held retenus", (int) $result['amount'] === $due - $held, json_encode($result));
    $check('retenue enregistrée sur le crédit', (int) Database::value('SELECT repaid_amount FROM credits WHERE id = ?', [$creditId]) === 10000 + $held);
    $check('retenue liée au versement', (int) Database::value("SELECT COUNT(*) FROM credit_repayments WHERE credit_id = ? AND source = 'payout'", [$creditId]) === 1);

    echo "\n8. Solde\n";
    $left = (int) Database::value('SELECT repay_total - repaid_amount FROM credits WHERE id = ?', [$creditId]);
    $post("/pro/credits/$creditId/remboursement", ['amount' => (string) ($left + 5000)]);
    $c = Database::one('SELECT * FROM credits WHERE id = ?', [$creditId]);
    $check('trop-perçu plafonné, crédit soldé tout seul', $c['status'] === 'repaid' && (int) $c['repaid_amount'] === 110000, json_encode($c));
    $check('le commerçant voit « Crédit remboursé »', ($money()['last_request']['status'] ?? '') === 'repaid');
    $check('il peut redemander un crédit', ($money()['can_request'] ?? false) === true);
    $check('notifications reçues', (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND id > ?', [$uid, $maxNotif]) >= 5);

    echo "\n9. Offre refusée\n";
    $call('POST', '/v1/merchant/credit-requests', $token, ['amount' => 50000]);
    $second = (int) Database::value('SELECT MAX(id) FROM credits WHERE merchant_user_id = ?', [$uid]);
    $post("/pro/credits/$second", ['status' => 'offered', 'amount' => '50000', 'repay_total' => '55000', 'term_months' => '3', 'withhold_pct' => '0']);
    $call('POST', '/v1/merchant/credit-offer', $token, ['accept' => false]);
    $check('offre refusée par le commerçant', Database::value('SELECT status FROM credits WHERE id = ?', [$second]) === 'declined');
} finally {
    // Remise en état complète, même si un test a échoué
    Database::run('UPDATE order_stalls SET payout_id = NULL WHERE stall_id = ? AND payout_id > ?', [$stallId, $maxPayout]);
    Database::run('DELETE FROM payouts WHERE stall_id = ? AND id > ?', [$stallId, $maxPayout]);
    Database::run('DELETE FROM credits WHERE merchant_user_id = ?', [$uid]);
    Database::run('DELETE FROM data_sharing_consents WHERE merchant_user_id = ?', [$uid]);
    Database::run('DELETE FROM notifications WHERE user_id = ? AND id > ?', [$uid, $maxNotif]);
    Database::run("DELETE FROM auth_tokens WHERE device = 'credit-flow-test'");
    @unlink($jar);
}
echo $fails === 0 ? "\nTout est bon.\n" : "\n$fails échec(s).\n";
exit($fails === 0 ? 0 : 1);
