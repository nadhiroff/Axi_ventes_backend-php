<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;

final class Payments
{
    public static function provider(): PaymentProvider
    {
        $name = (string) Config::get('payment_provider');
        return match (true) {
            $name === 'fake' && Config::isLocal() => new FakePaymentProvider(),
            default => throw new HttpException(503, 'PAYMENT_UNAVAILABLE', 'Le paiement Mobile Money n\'est pas encore disponible. Réessayez plus tard.'),
        };
    }

    /** Interroge le prestataire tant que la commande attend le paiement */
    public static function refresh(int $orderId): void
    {
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ? AND status = \'pending\' ORDER BY id DESC LIMIT 1', [$orderId]);
        if ($payment === null || $payment['provider_ref'] === null) {
            return;
        }
        $status = self::provider()->status($payment['provider_ref']);
        if ($status === 'succeeded') {
            self::markPaid($orderId, (int) $payment['id']);
        } elseif ($status === 'failed') {
            Database::transaction(static function () use ($orderId, $payment): void {
                Database::run('UPDATE payments SET status = \'failed\' WHERE id = ?', [$payment['id']]);
                Database::run('UPDATE orders SET status = \'cancelled\' WHERE id = ? AND status = \'awaiting_payment\'', [$orderId]);
                Database::run('UPDATE order_stalls SET status = \'cancelled\' WHERE order_id = ?', [$orderId]);
            });
        }
    }

    /** Paiement reçu : argent gardé par Axi, chaque étal doit préparer sa part, le client entre dans leur fichier */
    private static function markPaid(int $orderId, int $paymentId): void
    {
        Database::transaction(static function () use ($orderId, $paymentId): void {
            $now = Clock::now();
            $updated = Database::run('UPDATE orders SET status = \'paid\', paid_at = ? WHERE id = ? AND status = \'awaiting_payment\'', [$now, $orderId])->rowCount();
            if ($updated === 0) {
                return;
            }
            Database::run('UPDATE payments SET status = \'held\' WHERE id = ?', [$paymentId]);
            Database::run('UPDATE order_stalls SET status = \'to_prepare\' WHERE order_id = ? AND status = \'awaiting_payment\'', [$orderId]);

            $order = Database::one('SELECT o.*, u.name AS client_name FROM orders o JOIN users u ON u.id = o.client_id WHERE o.id = ?', [$orderId]);
            foreach (Database::all('SELECT stall_id FROM order_stalls WHERE order_id = ?', [$orderId]) as $part) {
                Database::run(
                    'INSERT INTO merchant_clients (stall_id, user_id, display_name, phone, orders_count, last_order_at, created_at)
                     VALUES (?, ?, ?, ?, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE orders_count = orders_count + 1, last_order_at = VALUES(last_order_at), user_id = COALESCE(user_id, VALUES(user_id))',
                    [$part['stall_id'], $order['client_id'], $order['client_name'], $order['payment_phone'], $now, $now],
                );
            }
        });
    }
}
