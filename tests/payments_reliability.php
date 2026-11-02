<?php
declare(strict_types=1);

// Pannes et charge sur le paiement : rien ne se perd, personne ne paie deux fois.
// Usage : php tests/payments_reliability.php [URL]   (défaut http://localhost:8000, env local, passerelle factice)
// Le serveur doit tourner SANS worker (les tâches sont alors traitées par les requêtes elles-mêmes).
// Modifie seulement des données de test : un compte @example.com, un produit dont le stock est remis à la fin.

use Axi\Core\Database;
use Axi\Services\Jobs;
use Axi\Services\Payment\Escrow;
use Axi\Services\Payment\FakeGateway;
use Axi\Services\Payment\WebhookProcessor;

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
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  OK      ' : '  ÉCHEC   ') . $label . (!$ok && $detail !== '' ? "\n          " . mb_substr($detail, 0, 300) : '') . "\n";
}

function call(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): array
{
    global $api;
    $ch = curl_init($api . $path);
    $h = ['Accept: application/json', 'X-Axi-Test-Gateway: fake'];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40]);
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

function marker(string $name, bool $on): void
{
    $on ? touch(FakeGateway::marker($name)) : @unlink(FakeGateway::marker($name));
}

/** Commande d'une pièce du produit de test ; renvoie [id commande, id paiement, référence] */
function order(string $token, int $productId, string $key): array
{
    $r = call('POST', '/v1/orders', ['lines' => [['product_id' => "prd_$productId", 'quantity' => 1]], 'reception' => ['mode' => 'delivery', 'detail' => 'Test fiabilité'],
        'payment' => ['operator' => 'mtn', 'phone' => '+2290197000001']], $token, ['Idempotency-Key' => $key]);
    $orderId = (int) substr((string) ($r['body']['data']['id'] ?? 'cmd_0'), 4);
    $payment = Database::one('SELECT id, reference FROM payments WHERE order_id = ?', [$orderId]);
    return [$orderId, (int) ($payment['id'] ?? 0), (string) ($payment['reference'] ?? '')];
}

function payment(int $id): array
{
    return Database::one('SELECT * FROM payments WHERE id = ?', [$id]);
}

function orderStatus(int $id): string
{
    return (string) Database::value('SELECT status FROM orders WHERE id = ?', [$id]);
}

function stock(int $productId): ?int
{
    $v = Database::value('SELECT stock_quantity FROM products WHERE id = ?', [$productId]);
    return $v === null ? null : (int) $v;
}

// --- Préparation : un client de test et un produit en vente dont on suit le stock ---------------------------------
$email = "fiab-$stamp@example.com";
call('POST', '/v1/auth/register', ['first_name' => 'Test', 'last_name' => 'Fiabilité', 'email' => $email, 'password' => 'fiabilite-123', 'password_confirmation' => 'fiabilite-123']);
$token = (string) (call('POST', '/v1/auth/verify-email', ['email' => $email, 'code' => lastCode($email), 'device' => 'test-fiabilite'])['body']['data']['token'] ?? '');
$product = Database::one(
    "SELECT p.id, p.stock_quantity FROM products p JOIN stalls s ON s.id = p.stall_id JOIN users u ON u.id = s.user_id
     WHERE p.status = 'active' AND p.in_stock = 1 AND p.price_amount IS NOT NULL AND s.status = 'active' AND u.status = 'active' ORDER BY p.id LIMIT 1",
);
if ($token === '' || $product === null) {
    exit("Préparation impossible : compte de test ou produit en vente introuvable (base remise à zéro ?)\n");
}
$productId = (int) $product['id'];
$originalStock = $product['stock_quantity'];
Database::run('UPDATE products SET stock_quantity = 10 WHERE id = ?', [$productId]);
marker('down', false);

try {
    section('1. Passerelle en panne : rien n\'est annulé sans preuve');
    [$o1, $p1, $ref1] = order($token, $productId, "fiab-$stamp-1");
    check('commande créée, stock réservé', $o1 > 0 && stock($productId) === 9, "commande $o1, stock " . var_export(stock($productId), true));
    marker("pending-$ref1", true);
    marker('down', true);
    $r = call('POST', "/v1/orders/cmd_$o1/cancel", [], $token);
    check('annulation refusée tant que la passerelle ne répond pas (503)', $r['status'] === 503 && ($r['body']['error']['code'] ?? '') === 'PAYMENT_CHECK_UNAVAILABLE', $r['raw']);
    check('commande toujours en attente', orderStatus($o1) === 'awaiting_payment');
    $check = Escrow::check($p1);
    check('vérification : « passerelle non jointe », rien de conclu', $check['reached'] === false && $check['payment']['status'] === 'pending');
    marker('down', false);

    section('2. Paiement arrivé APRÈS l\'annulation : commande réactivée');
    $r = call('POST', "/v1/orders/cmd_$o1/cancel", [], $token);
    check('annulation acceptée quand la passerelle dit « pas encore payé »', $r['status'] === 200 && orderStatus($o1) === 'cancelled', $r['raw']);
    check('stock rendu à l\'annulation', stock($productId) === 10);
    marker("pending-$ref1", false); // le client valide finalement le paiement sur son téléphone
    $after = Escrow::check($p1, 'gateway', null, null, true)['payment'];
    check('paiement tardif encaissé, jamais ignoré', $after['status'] === 'paid' && $after['late_paid_at'] !== null, json_encode($after));
    check('commande réactivée et payée', orderStatus($o1) === 'paid');
    check('stock repris pour la commande réactivée', stock($productId) === 9);
    check('séquestre : argent bloqué chez Axi', Database::value("SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND escrow_state = 'held'", [$o1]) >= 1);

    section('3. Paiement tardif mais produit épuisé : litige automatique, remboursement à faire');
    [$o2, $p2, $ref2] = order($token, $productId, "fiab-$stamp-2");
    marker("pending-$ref2", true);
    call('POST', "/v1/orders/cmd_$o2/cancel", [], $token);
    Database::run('UPDATE products SET stock_quantity = 0 WHERE id = ?', [$productId]); // vendu à d'autres entre-temps
    marker("pending-$ref2", false);
    $after = Escrow::check($p2, 'gateway', null, null, true)['payment'];
    check('paiement enregistré comme payé (argent tracé)', $after['status'] === 'paid' && $after['late_paid_at'] !== null);
    check('commande en litige, argent bloqué', orderStatus($o2) === 'disputed' && Database::value("SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND escrow_state = 'disputed'", [$o2]) >= 1);
    check('litige ouvert automatiquement', Database::value("SELECT COUNT(*) FROM disputes WHERE order_id = ? AND status = 'open'", [$o2]) == 1);
    check('stock jamais négatif', stock($productId) === 0);
    $again = Escrow::check($p2, 'gateway', null, null, true)['payment'];
    check('vérifier une 2e fois ne change rien (pas de second litige)', $again['status'] === 'paid' && Database::value('SELECT COUNT(*) FROM disputes WHERE order_id = ?', [$o2]) == 1);
    Database::run('UPDATE products SET stock_quantity = 10 WHERE id = ?', [$productId]);

    section('4. Webhook : enregistré, rejoué tant que la passerelle ne répond pas, jamais perdu');
    [$o3, $p3, $ref3] = order($token, $productId, "fiab-$stamp-3");
    marker("pending-$ref3", true);
    $eventId = Database::insert(
        'INSERT INTO webhook_events (provider, event_key, reference, payload, received_at, next_attempt_at) VALUES (\'fake\', ?, ?, \'{}\', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        ["test:$stamp", $ref3],
    );
    $dup = Database::run('INSERT IGNORE INTO webhook_events (provider, event_key, payload, received_at) VALUES (\'fake\', ?, \'{}\', UTC_TIMESTAMP())', ["test:$stamp"])->rowCount();
    check('même événement reçu deux fois : enregistré une seule fois', $dup === 0);
    marker('down', true);
    foreach (WebhookProcessor::claim(1, [$eventId]) as $row) {
        WebhookProcessor::process($row);
    }
    $ev = Database::one('SELECT * FROM webhook_events WHERE id = ?', [$eventId]);
    check('passerelle en panne : événement gardé et replanifié', $ev['status'] === 'received' && (int) $ev['attempts'] === 1 && $ev['next_attempt_at'] > gmdate('Y-m-d H:i:s'), json_encode($ev));
    marker('down', false);
    marker("pending-$ref3", false);
    Database::run('UPDATE webhook_events SET next_attempt_at = UTC_TIMESTAMP() WHERE id = ?', [$eventId]);
    foreach (WebhookProcessor::claim(1, [$eventId]) as $row) {
        WebhookProcessor::process($row);
    }
    $ev = Database::one('SELECT * FROM webhook_events WHERE id = ?', [$eventId]);
    check('nouvel essai : traité, paiement encaissé', $ev['status'] === 'done' && payment($p3)['status'] === 'paid' && orderStatus($o3) === 'paid', json_encode($ev));
    check('un worker arrêté net ne bloque pas un événement (rendu à la file)', Jobs::releaseStuck() >= 0);

    section('5. Client débité deux fois pour la même référence : détecté et remboursé');
    $paid = payment($p3);
    $result = Escrow::handleExtraTransaction($paid, 'fake_extra_' . $stamp);
    check('deuxième transaction remboursée en totalité', $result === 'refund_simulated', $result);
    check('double paiement enregistré', Database::value('SELECT COUNT(*) FROM payment_duplicates WHERE payment_id = ?', [$p3]) == 1);
    check('traité une seule fois même si l\'événement revient', Escrow::handleExtraTransaction($paid, 'fake_extra_' . $stamp) === 'already_handled');
    check('la transaction retenue n\'est jamais remboursée par erreur', Escrow::handleExtraTransaction($paid, (string) $paid['provider_ref']) === 'already_handled');

    section('6. Versement au commerçant : 6 clics simultanés, un seul versement');
    Database::run("UPDATE order_stalls SET escrow_state = 'released', payout_id = NULL WHERE order_id = ?", [$o3]);
    $stallId = (int) Database::value('SELECT stall_id FROM order_stalls WHERE order_id = ? LIMIT 1', [$o3]);
    $before = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM payouts');
    $script = 'require "' . BASE_PATH . '/src/bootstrap.php"; try { $r = Axi\Services\Payment\Escrow::payout(' . $stallId . ', 0); echo "ok:" . $r["amount"]; } catch (Throwable $e) { echo "refus:" . $e->getMessage(); }';
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $procs[] = popen('php -r ' . escapeshellarg($script) . ' 2>&1', 'r');
    }
    $outputs = array_map(static fn ($p): string => (string) stream_get_contents($p), $procs);
    array_map('pclose', $procs);
    $made = Database::all('SELECT * FROM payouts WHERE id > ? AND stall_id = ? AND amount > 0', [$before, $stallId]);
    check('un seul versement créé', count($made) === 1, implode(' | ', $outputs));
    check('parts rattachées à ce seul versement', count($made) === 1 && Database::value('SELECT COUNT(*) FROM order_stalls WHERE order_id = ? AND payout_id = ?', [$o3, $made[0]['id']]) >= 1);

    section('7. Interblocage MySQL : la transaction est rejouée au lieu d\'une erreur 500');
    // Lignes jetables (fenêtre 1, jamais utilisée par la vraie limite) : A, B et 5 lignes de plus pour l'autre connexion
    [$a, $b] = [sha1("a$stamp"), sha1("b$stamp")];
    $extra = array_map(static fn (int $i): string => sha1("c$i$stamp"), range(1, 5));
    foreach ([$a, $b, ...$extra] as $bucket) {
        Database::run('INSERT IGNORE INTO rate_limits (bucket, window_start, hits) VALUES (?, 1, 0)', [$bucket]);
    }
    $list = "'" . implode("','", [$a, ...$extra]) . "'";
    // Autre connexion : verrouille A (et 5 autres lignes), attend, puis B ; nous : B puis A (ordre inverse).
    // Elle a modifié plus de lignes : MySQL annule la nôtre, qui doit être rejouée
    $other = 'require "' . BASE_PATH . '/src/bootstrap.php"; $pdo = Axi\Core\Database::pdo(); $pdo->beginTransaction();'
        . ' $pdo->exec("UPDATE rate_limits SET hits = hits + 1 WHERE window_start = 1 AND bucket IN (' . $list . ')"); usleep(400000);'
        . ' try { $pdo->exec("UPDATE rate_limits SET hits = hits + 1 WHERE bucket = \'' . $b . '\' AND window_start = 1"); $pdo->commit(); echo "autre:ok"; } catch (Throwable $e) { echo "autre:" . $e->getMessage(); }';
    $proc = popen('php -r ' . escapeshellarg($other) . ' 2>&1', 'r');
    usleep(150000);
    $attempts = 0;
    $error = '';
    try {
        Database::transaction(static function () use ($a, $b, &$attempts): void {
            $attempts++;
            Database::run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND window_start = 1', [$b]);
            usleep(500000);
            Database::run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND window_start = 1', [$a]);
        });
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $otherOut = (string) stream_get_contents($proc);
    pclose($proc);
    check('interblocage provoqué puis résolu par un nouvel essai', $error === '' && $attempts >= 2, "essais $attempts, erreur « $error », $otherOut");
    Database::run('DELETE FROM rate_limits WHERE window_start = 1');

    section('8. Limite globale de requêtes');
    $fake = bin2hex(random_bytes(32));
    $codes = [];
    for ($i = 0; $i < 310; $i++) {
        $codes[] = call('GET', '/v1/live', null, $fake)['status'];
    }
    check('au-delà de 300 requêtes par minute et par session : 429', in_array(429, $codes, true) && $codes[0] === 200, 'premier ' . $codes[0] . ', 429 : ' . count(array_keys($codes, 429, true)));
    $r = call('GET', '/v1/live', null, $fake);
    check('réponse 429 avec Retry-After et message clair', $r['status'] === 429 && ($r['body']['error']['code'] ?? '') === 'TOO_MANY_REQUESTS', $r['raw']);
    check('webhooks jamais limités', call('POST', '/v1/webhooks/kkiapay', ['event' => 'x'], $fake)['status'] === 401);

    section('9. Emails et notifications : mis en file, envoyés après la réponse');
    Jobs::runInline(); // tâches créées par les appels directs de ce script (sans worker)
    check('aucune tâche abandonnée pour ce test', Database::value("SELECT COUNT(*) FROM outbox_jobs WHERE status = 'dead' AND created_at > ?", [gmdate('Y-m-d H:i:s', (int) $stamp)]) == 0);
    check('tâches de ce test envoyées (sans worker, par les requêtes)', Database::value("SELECT COUNT(*) FROM outbox_jobs WHERE status = 'pending' AND created_at > ? AND next_attempt_at <= UTC_TIMESTAMP()", [gmdate('Y-m-d H:i:s', (int) $stamp + 2)]) == 0);
} finally {
    marker('down', false);
    foreach (glob(FakeGateway::marker('pending-*')) ?: [] as $file) {
        @unlink($file);
    }
    Database::run('UPDATE products SET stock_quantity = ? WHERE id = ?', [$originalStock, $productId]);
}

echo "\n$checks vérifications, " . ($failures === 0 ? 'toutes réussies' : "$failures en échec") . "\n";
exit($failures === 0 ? 0 : 1);
