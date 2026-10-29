<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Ids;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Services\Orders;
use Axi\Support\Merchant;

/** « Ma journée » */
final class TodayController
{
    private const STATUS_LABELS = ['to_prepare' => 'À préparer', 'ready' => 'Prête', 'picked_up' => 'Remise au coursier'];

    public function today(Request $req): never
    {
        $stall = Merchant::stall($req);
        $dayStart = Clock::localDayStart();

        $sales = (int) Database::value(
            'SELECT COALESCE(SUM(os.items_total), 0) FROM order_stalls os JOIN orders o ON o.id = os.order_id
             WHERE os.stall_id = ? AND os.status NOT IN (\'awaiting_payment\', \'cancelled\') AND o.paid_at >= ?',
            [$stall['id'], $dayStart],
        );
        $rows = Database::all(
            'SELECT os.id AS part_id, os.status, o.id AS order_id, o.reception_mode, o.reception_detail, o.payment_phone,
                    u.name AS customer_name, p.name AS axi_point_name
             FROM order_stalls os JOIN orders o ON o.id = os.order_id JOIN users u ON u.id = o.client_id
             LEFT JOIN axi_points p ON p.id = o.axi_point_id
             WHERE os.stall_id = ? AND (os.status IN (\'to_prepare\', \'ready\') OR (os.status = \'picked_up\' AND os.updated_at >= ?))
             ORDER BY os.status = \'to_prepare\' DESC, o.id DESC LIMIT 50',
            [$stall['id'], $dayStart],
        );

        $orders = [];
        foreach ($rows as $row) {
            $items = Database::all('SELECT name, quantity FROM order_items WHERE order_stall_id = ?', [$row['part_id']]);
            $orders[] = [
                'id' => Ids::encode('cmd', $row['order_id']),
                'customer_name' => $row['customer_name'],
                'customer_phone' => $row['payment_phone'],
                'summary' => implode(', ', array_map(static fn (array $i): string => $i['quantity'] . ' × ' . $i['name'], $items)) . ' · ' . Orders::receptionLabel($row),
                'status' => $row['status'],
                'status_label' => self::STATUS_LABELS[$row['status']],
            ];
        }

        $subscription = Database::one('SELECT ends_on FROM subscriptions WHERE stall_id = ? ORDER BY ends_on DESC LIMIT 1', [$stall['id']]);
        $active = $subscription !== null && $subscription['ends_on'] >= Clock::localDate();

        Response::ok([
            'greeting_name' => $stall['owner_name'],
            'stall_open' => (bool) $stall['is_open'],
            'sales_today' => $sales,
            'orders_to_prepare' => count(array_filter($orders, static fn (array $o): bool => $o['status'] === 'to_prepare')),
            'connection' => ['active' => $active, 'included_until' => $subscription['ends_on'] ?? null],
            'relocation' => $this->relocation($stall),
            'orders' => $orders,
        ], 200, 60);
    }

    /** « Prêt pour le coursier » */
    public function ready(Request $req): never
    {
        $stall = Merchant::stall($req);
        $orderId = $req->id('cmd', 'ORDER_NOT_FOUND', 'Commande introuvable.');
        $part = Database::one('SELECT * FROM order_stalls WHERE order_id = ? AND stall_id = ?', [$orderId, $stall['id']])
            ?? throw new HttpException(404, 'ORDER_NOT_FOUND', 'Commande introuvable.');
        if ($part['status'] === 'to_prepare') {
            Database::run('UPDATE order_stalls SET status = \'ready\', ready_at = ? WHERE id = ?', [Clock::now(), $part['id']]);
            Orders::syncStatus($orderId);
        } elseif ($part['status'] !== 'ready') {
            throw new HttpException(409, 'ORDER_NOT_PREPARABLE', 'Cette commande ne peut plus être marquée prête.');
        }
        Response::ok(['status' => 'ready']);
    }

    /** Encart « nouvelle adresse » pendant 90 jours après un déménagement */
    private function relocation(array $stall): ?array
    {
        if ($stall['moved_at'] === null || $stall['moved_at'] < Clock::now(-90 * 86400)) {
            return null;
        }
        return [
            // Formulation sans accord de genre : le profil ne le précise pas
            'title' => 'Votre étal est maintenant à ' . $stall['site_name'],
            'text' => 'Prévenez vos clients : votre nouvelle adresse et votre plan d\'accès leur sont envoyés en un message.',
            'already_sent' => $stall['relocation_sent_at'] !== null && $stall['relocation_sent_at'] >= $stall['moved_at'],
            'client_count' => (int) Database::value('SELECT COUNT(*) FROM merchant_clients WHERE stall_id = ?', [$stall['id']]),
        ];
    }
}
