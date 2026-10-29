<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Config;
use Axi\Core\Url;
use Axi\Models\DeliveryOption;

/**
 * Calcul du panier multi-étals :
 *   sous-total + frais de regroupement (par étal au-delà du premier) + frais de livraison.
 */
final class CartPricing
{
    /** Prix unitaire : prix de gros dès la quantité minimale atteinte */
    public static function unitPrice(array $line): int
    {
        if ($line['wholesale_price'] !== null && $line['wholesale_min_qty'] !== null && (int) $line['quantity'] >= (int) $line['wholesale_min_qty']) {
            return (int) $line['wholesale_price'];
        }
        return (int) $line['price'];
    }

    public static function groupingFee(int $stallsCount): int
    {
        return max(0, $stallsCount - 1) * (int) Config::get('pricing.grouping_fee_per_extra_stall');
    }

    public static function isAvailable(array $line): bool
    {
        return $line['product_status'] === 'active' && $line['stall_status'] === 'active' && $line['stock_status'] !== 'out';
    }

    /** $lines : CartItem::lines() ; $option : ligne delivery_options ou null */
    public static function quote(array $lines, ?array $option): array
    {
        $stalls = [];
        $unavailable = [];
        foreach ($lines as $line) {
            $available = self::isAvailable($line);
            $unitPrice = self::unitPrice($line);
            $item = [
                'product_id' => (int) $line['product_id'],
                'name' => $line['name'],
                'unit' => $line['unit'],
                'photo_url' => Url::asset($line['photo_path']),
                'unit_price' => $unitPrice,
                'wholesale_applied' => $unitPrice !== (int) $line['price'],
                'quantity' => (int) $line['quantity'],
                'line_total' => $available ? $unitPrice * (int) $line['quantity'] : 0,
                'available' => $available,
            ];
            if (!$available) {
                $unavailable[] = $item['product_id'];
            }
            $id = (int) $line['stall_id'];
            $stalls[$id] ??= [
                'stall' => ['id' => $id, 'name' => $line['stall_name'], 'market' => $line['market_name'], 'verified' => $line['verified_at'] !== null],
                'items' => [],
                'subtotal' => 0,
            ];
            $stalls[$id]['items'][] = $item;
            $stalls[$id]['subtotal'] += $item['line_total'];
        }

        // Un étal dont tous les produits sont indisponibles ne compte pas dans le regroupement
        $billable = array_filter($stalls, static fn (array $s): bool => $s['subtotal'] > 0);
        $subtotal = array_sum(array_column($billable, 'subtotal'));
        $groupingFee = self::groupingFee(count($billable));
        $deliveryFee = $option === null || $subtotal === 0 ? null : (int) $option['fee'];

        return [
            'stalls' => array_values($stalls),
            'stalls_count' => count($billable),
            'items_count' => array_sum(array_map(static fn (array $l): int => (int) $l['quantity'], $lines)),
            'unavailable_product_ids' => $unavailable,
            'subtotal' => $subtotal,
            'grouping_fee' => $groupingFee,
            'grouping_fee_per_extra_stall' => (int) Config::get('pricing.grouping_fee_per_extra_stall'),
            'delivery' => $option === null ? null : DeliveryOption::present($option),
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal + $groupingFee + ($deliveryFee ?? 0),
            'escrow' => true,
        ];
    }
}
