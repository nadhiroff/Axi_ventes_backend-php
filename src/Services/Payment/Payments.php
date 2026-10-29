<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Services\Orders;

final class Payments
{
    public const INSTRUCTIONS = 'Un message de validation arrive sur votre téléphone. Entrez votre code secret Mobile Money pour confirmer.';

    public static function provider(): PaymentProvider
    {
        $name = (string) Config::get('payment_provider');
        return match (true) {
            $name === 'fake' && Config::isLocal() => new FakePaymentProvider(),
            default => throw new HttpException(503, 'PAYMENT_UNAVAILABLE', 'Le paiement Mobile Money n\'est pas encore disponible. Réessayez plus tard.'),
        };
    }

    /**
     * Envoie la demande de paiement si elle n'est pas encore partie (provider_ref vide).
     * Appelée à la création et à chaque nouvel essai de la même commande : la référence Axi
     * ne change jamais, donc le prestataire ne débite qu'une fois.
     * @return string|null instructions à afficher, ou null si la demande a échoué (réseau)
     */
    public static function ensureInitiated(int $orderId): ?string
    {
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ?', [$orderId]);
        if ($payment === null || $payment['status'] !== 'pending') {
            return null;
        }
        if ($payment['provider_ref'] !== null) {
            return self::INSTRUCTIONS;
        }
        try {
            $init = self::provider()->initiate($payment['reference'], (int) $payment['amount'], $payment['operator'], $payment['phone']);
        } catch (HttpException $e) {
            // Aucun prestataire configuré : erreur claire, rien n'a été demandé au client
            throw $e;
        } catch (\Throwable $e) {
            // Réseau ou prestataire indisponible : la commande reste en attente, un nouvel essai reprendra la même référence
            Logger::write('payments', "ÉCHEC d'envoi {$payment['reference']} : " . $e->getMessage());
            return null;
        }
        Database::run('UPDATE payments SET provider_ref = ? WHERE id = ? AND provider_ref IS NULL', [$init['ref'], $payment['id']]);
        return $init['instructions'];
    }

    /** Interroge le prestataire tant que la commande attend le paiement */
    public static function refresh(int $orderId): void
    {
        $payment = Database::one('SELECT * FROM payments WHERE order_id = ? AND status = \'pending\'', [$orderId]);
        if ($payment === null || $payment['provider_ref'] === null) {
            return;
        }
        try {
            $status = self::provider()->status($payment['provider_ref']);
        } catch (\Throwable $e) {
            Logger::write('payments', "Statut indisponible {$payment['reference']} : " . $e->getMessage());
            return;
        }
        if ($status === 'succeeded') {
            self::markPaid($orderId, (int) $payment['id']);
        } elseif ($status === 'failed') {
            Database::transaction(static function () use ($orderId, $payment): void {
                Database::run('UPDATE payments SET status = \'failed\' WHERE id = ? AND status = \'pending\'', [$payment['id']]);
                Database::run('UPDATE orders SET status = \'cancelled\' WHERE id = ? AND status = \'awaiting_payment\'', [$orderId]);
                Database::run('UPDATE order_stalls SET status = \'cancelled\' WHERE order_id = ? AND status = \'awaiting_payment\'', [$orderId]);
            });
        }
    }

    /** Réception confirmée : l'argent gardé par Axi est libéré aux commerçants */
    public static function release(int $orderId): void
    {
        Database::transaction(static function () use ($orderId): void {
            $now = Clock::now();
            $updated = Database::run(
                'UPDATE orders SET status = \'delivered\', delivered_at = ? WHERE id = ? AND status IN (\'paid\', \'preparing\', \'on_the_way\')',
                [$now, $orderId],
            )->rowCount();
            if ($updated === 0) {
                return;
            }
            Database::run('UPDATE order_stalls SET status = \'delivered\', delivered_at = ? WHERE order_id = ? AND status <> \'cancelled\'', [$now, $orderId]);
            Database::run('UPDATE payments SET status = \'released\', released_at = ? WHERE order_id = ? AND status = \'held\'', [$now, $orderId]);
        });
    }

    /**
     * Paiement reçu : argent gardé par Axi, chaque étal doit préparer sa part, le client entre dans leur fichier.
     * Le UPDATE conditionnel garantit qu'une confirmation reçue deux fois n'a d'effet qu'une fois.
     */
    private static function markPaid(int $orderId, int $paymentId): void
    {
        Database::transaction(static function () use ($orderId, $paymentId): void {
            $now = Clock::now();
            $updated = Database::run('UPDATE orders SET status = \'paid\', paid_at = ? WHERE id = ? AND status = \'awaiting_payment\'', [$now, $orderId])->rowCount();
            if ($updated === 0) {
                return;
            }
            Database::run('UPDATE payments SET status = \'held\' WHERE id = ? AND status = \'pending\'', [$paymentId]);
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
        Orders::syncStatus($orderId);
    }
}
