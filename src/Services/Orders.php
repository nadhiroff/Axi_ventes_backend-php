<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Database;

/** Statut global d'une commande, déduit de l'avancement de chaque étal */
final class Orders
{
    public const STALL_LABELS = [
        'awaiting_payment' => 'En attente du paiement',
        'to_prepare' => 'Prépare votre commande',
        'ready' => 'Prête pour le coursier',
        'picked_up' => 'Remise au coursier',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ];

    public static function syncStatus(int $orderId): void
    {
        $statuses = array_column(Database::all('SELECT status FROM order_stalls WHERE order_id = ? AND status <> \'cancelled\'', [$orderId]), 'status');
        $order = Database::one('SELECT status FROM orders WHERE id = ?', [$orderId]);
        if ($order === null || $statuses === [] || in_array($order['status'], ['awaiting_payment', 'delivered', 'cancelled'], true)) {
            return;
        }
        $all = static fn (array $allowed): bool => array_diff($statuses, $allowed) === [];
        $status = match (true) {
            $all(['picked_up', 'delivered']) => 'on_the_way',
            $all(['to_prepare']) => 'paid',
            default => 'preparing',
        };
        Database::run('UPDATE orders SET status = ? WHERE id = ?', [$status, $orderId]);
    }

    /** « Livraison Akpakpa », « Expédition car Parakou », « Retrait Point Axi Tokpa Yôyô » */
    public static function receptionLabel(array $order): string
    {
        return match ($order['reception_mode']) {
            'delivery' => 'Livraison ' . $order['reception_detail'],
            'bus' => 'Expédition car ' . $order['reception_detail'],
            'axi_point' => 'Retrait ' . ($order['axi_point_name'] ?? 'au point Axi'),
        };
    }
}
