<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Page;
use Axi\Core\Url;

final class Order
{
    public static function findForClient(int $id, int $clientId): ?array
    {
        return Database::one('SELECT * FROM orders WHERE id = ? AND client_id = ?', [$id, $clientId]);
    }

    public static function listForClient(int $clientId, Page $page): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM orders WHERE client_id = ?', [$clientId]);
        $rows = Database::all(
            'SELECT o.*, (SELECT COUNT(*) FROM stall_orders so WHERE so.order_id = o.id) AS stalls_count
             FROM orders o WHERE o.client_id = ? ORDER BY o.id DESC' . $page->limitSql(),
            [$clientId]
        );
        return [array_map(static fn (array $r): array => self::presentSummary($r) + ['stalls_count' => (int) $r['stalls_count']], $rows), $total];
    }

    /** Détail d'une commande, regroupé par étal */
    public static function present(array $order): array
    {
        $stallOrders = Database::all(
            'SELECT so.id, so.stall_id, so.subtotal, so.status, s.name AS stall_name, s.photo_path, m.name AS market_name, s.row_label, s.stall_number
             FROM stall_orders so JOIN stalls s ON s.id = so.stall_id JOIN markets m ON m.id = s.market_id
             WHERE so.order_id = ? ORDER BY so.id',
            [$order['id']]
        );
        $items = self::itemsByStallOrder(array_column($stallOrders, 'id'));
        $delivery = Database::one('SELECT * FROM delivery_options WHERE id = ?', [$order['delivery_option_id']]);
        $payment = Database::one('SELECT id, provider, status, amount FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$order['id']]);

        return self::presentSummary($order) + [
            'subtotal' => (int) $order['subtotal'],
            'grouping_fee' => (int) $order['grouping_fee'],
            'delivery_fee' => (int) $order['delivery_fee'],
            'refund_due' => (int) $order['refund_due'],
            'delivery' => [
                'option' => $delivery === null ? null : DeliveryOption::present($delivery),
                'address' => $order['delivery_address'],
                'contact_phone' => $order['contact_phone'],
            ],
            'note' => $order['note'],
            'received_at' => Clock::iso($order['received_at']),
            'payment' => $payment === null ? null : [
                'id' => (int) $payment['id'],
                'provider' => $payment['provider'],
                'status' => $payment['status'],
                'amount' => (int) $payment['amount'],
            ],
            'stalls' => array_map(static fn (array $so): array => [
                'stall_order_id' => (int) $so['id'],
                'stall' => [
                    'id' => (int) $so['stall_id'],
                    'name' => $so['stall_name'],
                    'photo_url' => Url::asset($so['photo_path']),
                    'location' => Stall::locationLabel($so['market_name'], $so['row_label'], $so['stall_number']),
                ],
                'status' => $so['status'],
                'subtotal' => (int) $so['subtotal'],
                'items' => $items[$so['id']] ?? [],
            ], $stallOrders),
        ];
    }

    public static function presentSummary(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'ref' => $r['ref'],
            'status' => $r['status'],
            'total' => (int) $r['total'],
            'created_at' => Clock::iso($r['created_at']),
            'paid_at' => Clock::iso($r['paid_at']),
        ];
    }

    /** Lignes de commande indexées par stall_order_id */
    public static function itemsByStallOrder(array $stallOrderIds): array
    {
        if ($stallOrderIds === []) {
            return [];
        }
        $rows = Database::all(
            'SELECT stall_order_id, product_id, name, unit, unit_price, quantity, line_total FROM order_items
             WHERE stall_order_id IN (' . Database::in($stallOrderIds) . ') ORDER BY id',
            $stallOrderIds
        );
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['stall_order_id']][] = [
                'product_id' => (int) $r['product_id'],
                'name' => $r['name'],
                'unit' => $r['unit'],
                'unit_price' => (int) $r['unit_price'],
                'quantity' => (int) $r['quantity'],
                'line_total' => (int) $r['line_total'],
            ];
        }
        return $grouped;
    }
}
