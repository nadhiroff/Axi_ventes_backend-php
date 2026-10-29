<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Models\CartItem;
use Axi\Models\Customer;
use Axi\Models\Notification;
use Axi\Models\User;

final class OrderService
{
    /**
     * Transforme le panier en commande (prix figés). Le paiement est lancé ensuite par l'appelant.
     * Renvoie l'id de la commande.
     */
    public static function createFromCart(int $clientId, array $option, ?string $address, string $contactPhone, ?string $note): int
    {
        return Database::transaction(static function () use ($clientId, $option, $address, $contactPhone, $note): int {
            $lines = CartItem::lines($clientId);
            if ($lines === []) {
                throw new HttpException(422, 'CART_EMPTY', 'Votre panier est vide');
            }
            $quote = CartPricing::quote($lines, $option);
            if ($quote['unavailable_product_ids'] !== []) {
                throw new HttpException(409, 'PRODUCT_UNAVAILABLE', 'Certains produits ne sont plus disponibles', ['product_ids' => $quote['unavailable_product_ids']]);
            }

            $now = Clock::now();
            $orderId = Database::insert(
                'INSERT INTO orders (client_id, subtotal, grouping_fee, delivery_fee, total, delivery_option_id, delivery_address, contact_phone, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$clientId, $quote['subtotal'], $quote['grouping_fee'], $quote['delivery_fee'], $quote['total'], (int) $option['id'], $address, $contactPhone, $note, $now]
            );
            Database::run('UPDATE orders SET ref = ? WHERE id = ?', [sprintf('AX%06d', $orderId), $orderId]);

            $rate = (float) Config::get('pricing.commission_rate');
            foreach ($quote['stalls'] as $group) {
                $commission = (int) round($group['subtotal'] * $rate);
                $stallOrderId = Database::insert(
                    'INSERT INTO stall_orders (order_id, stall_id, subtotal, commission, net_amount, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$orderId, $group['stall']['id'], $group['subtotal'], $commission, $group['subtotal'] - $commission, $now]
                );
                Database::insertMany(
                    'order_items',
                    ['stall_order_id', 'product_id', 'name', 'unit', 'unit_price', 'quantity', 'line_total'],
                    array_map(static fn (array $i): array => [$stallOrderId, $i['product_id'], $i['name'], $i['unit'], $i['unit_price'], $i['quantity'], $i['line_total']], $group['items'])
                );
            }
            CartItem::clear($clientId);
            return $orderId;
        });
    }

    /** Paiement reçu (séquestre) : la commande part chez les commerçants. Appelé dans une transaction. */
    public static function markPaid(int $orderId): void
    {
        Database::run("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'pending_payment'", [Clock::now(), $orderId]);
        Database::run("UPDATE stall_orders SET status = 'new' WHERE order_id = ? AND status = 'pending_payment'", [$orderId]);

        $order = Database::one('SELECT id, ref, client_id FROM orders WHERE id = ?', [$orderId]);
        $client = User::findById((int) $order['client_id']);
        $stallOrders = Database::all(
            'SELECT so.stall_id, so.subtotal, s.merchant_id FROM stall_orders so JOIN stalls s ON s.id = so.stall_id WHERE so.order_id = ?',
            [$orderId]
        );
        foreach ($stallOrders as $so) {
            Customer::recordOrder((int) $so['stall_id'], $client, (int) $so['subtotal']);
            Notification::send(
                (int) $so['merchant_id'],
                'order_new',
                'Nouvelle commande ' . $order['ref'],
                $client['name'] . ' a payé ' . $so['subtotal'] . ' FCFA. L\'argent est gardé par Axi jusqu\'à la réception.',
                ['order_ref' => $order['ref']]
            );
        }
    }

    /** Réception confirmée (client) ou libération automatique (cron) : l'argent est libéré aux commerçants. */
    public static function release(int $orderId): bool
    {
        return Database::transaction(static function () use ($orderId): bool {
            $updated = Database::run(
                "UPDATE orders SET status = 'completed', received_at = ? WHERE id = ? AND status = 'paid'",
                [Clock::now(), $orderId]
            )->rowCount();
            if ($updated !== 1) {
                return false;
            }
            Database::run("UPDATE stall_orders SET status = 'received' WHERE order_id = ? AND status IN ('new', 'confirmed', 'ready')", [$orderId]);
            Database::run("UPDATE payments SET status = 'released' WHERE order_id = ? AND status = 'held'", [$orderId]);

            $ref = Database::value('SELECT ref FROM orders WHERE id = ?', [$orderId]);
            $rows = Database::all(
                "SELECT s.merchant_id, so.net_amount FROM stall_orders so JOIN stalls s ON s.id = so.stall_id WHERE so.order_id = ? AND so.status = 'received'",
                [$orderId]
            );
            foreach ($rows as $r) {
                Notification::send((int) $r['merchant_id'], 'payment_released', 'Argent libéré', "Commande $ref reçue : {$r['net_amount']} FCFA disponibles.", ['order_ref' => $ref]);
            }
            return true;
        });
    }

    /** Le commerçant annule sa part : le client sera remboursé de ce montant. */
    public static function cancelStallOrder(array $stallOrder): void
    {
        Database::transaction(static function () use ($stallOrder): void {
            $done = Database::run(
                "UPDATE stall_orders SET status = 'cancelled' WHERE id = ? AND status IN ('new', 'confirmed')",
                [$stallOrder['id']]
            )->rowCount();
            if ($done !== 1) {
                throw new HttpException(409, 'ORDER_STATUS_CONFLICT', 'Cette commande ne peut plus être annulée');
            }
            Database::run('UPDATE orders SET refund_due = refund_due + ? WHERE id = ?', [$stallOrder['subtotal'], $stallOrder['order_id']]);

            // Tous les étals ont annulé : la commande entière est annulée et remboursée
            $remaining = (int) Database::value("SELECT COUNT(*) FROM stall_orders WHERE order_id = ? AND status <> 'cancelled'", [$stallOrder['order_id']]);
            if ($remaining === 0) {
                Database::run("UPDATE orders SET status = 'cancelled', refund_due = total WHERE id = ?", [$stallOrder['order_id']]);
            }
            $order = Database::one('SELECT ref, client_id FROM orders WHERE id = ?', [$stallOrder['order_id']]);
            Notification::send(
                (int) $order['client_id'],
                'order_cancelled',
                'Commande ' . $order['ref'] . ' : article annulé',
                "Un commerçant n'a pas pu honorer sa part. Vous serez remboursé de {$stallOrder['subtotal']} FCFA.",
                ['order_ref' => $order['ref']]
            );
        });
    }
}
