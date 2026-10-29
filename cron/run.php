<?php
declare(strict_types=1);

/**
 * Tâches planifiées, à lancer toutes les 5 minutes (cPanel > Tâches cron) :
 *   php /chemin/vers/Axi_ventes_backend-php/cron/run.php
 * - envoie les SMS en attente (clients sans l'application)
 * - annule les commandes jamais payées
 * - libère l'argent des commandes remises sans confirmation du client
 * - supprime les anciens codes SMS
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\Logger;
use Axi\Services\OrderService;
use Axi\Services\Sms\LogSmsSender;

// 1. SMS en attente, par paquets de 100
$sms = new LogSmsSender();
$sent = 0;
foreach (Database::all("SELECT id, phone, message FROM sms_queue WHERE status = 'queued' AND attempts < 3 ORDER BY id LIMIT 100") as $row) {
    try {
        $sms->send($row['phone'], $row['message']);
        Database::run("UPDATE sms_queue SET status = 'sent', sent_at = ?, attempts = attempts + 1 WHERE id = ?", [Clock::now(), $row['id']]);
        $sent++;
    } catch (Throwable $e) {
        Database::run("UPDATE sms_queue SET attempts = attempts + 1, status = IF(attempts >= 3, 'failed', 'queued') WHERE id = ?", [$row['id']]);
        Logger::write('error', 'SMS #' . $row['id'] . ' : ' . $e->getMessage());
    }
}

// 2. Commandes jamais payées
$expired = Database::run(
    "UPDATE orders SET status = 'cancelled' WHERE status = 'pending_payment' AND created_at < ?",
    [Clock::now(-3600 * (int) Config::get('escrow.unpaid_expiry_hours'))]
)->rowCount();
Database::run("UPDATE stall_orders so JOIN orders o ON o.id = so.order_id SET so.status = 'cancelled' WHERE o.status = 'cancelled' AND so.status = 'pending_payment'");
Database::run("UPDATE payments p JOIN orders o ON o.id = p.order_id SET p.status = 'failed' WHERE o.status = 'cancelled' AND p.status = 'pending'");

// 3. Libération automatique : toutes les parts remises (ready) et paiement ancien de N jours
$released = 0;
$due = Database::all(
    "SELECT o.id FROM orders o
     WHERE o.status = 'paid' AND o.paid_at < ?
       AND NOT EXISTS (SELECT 1 FROM stall_orders so WHERE so.order_id = o.id AND so.status IN ('new', 'confirmed'))
     LIMIT 200",
    [Clock::now(-86400 * (int) Config::get('escrow.auto_release_days'))]
);
foreach ($due as $row) {
    $released += OrderService::release((int) $row['id']) ? 1 : 0;
}

// 4. Ménage
Database::run('DELETE FROM otp_codes WHERE created_at < ?', [Clock::now(-86400)]);

echo sprintf("[%s] sms=%d commandes_expirees=%d liberations=%d\n", Clock::now(), $sent, $expired, $released);
