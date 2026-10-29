<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;

/**
 * Données agrégées et anonymes pour Axi Pro (ANaGeM, annonceurs, financeurs).
 * Aucun nom, numéro ou identifiant d'étal n'est renvoyé, et tout groupe de moins de
 * pro.min_group_size étals est masqué pour qu'on ne puisse pas reconnaître un commerçant.
 */
final class ProAnalytics
{
    public static function minGroup(): int
    {
        return (int) Config::get('pro.min_group_size');
    }

    /** Recensement des étals (pour l'ANaGeM), sous-locataires et commerçants sans place compris */
    public static function census(?int $marketId): array
    {
        $counts = "COUNT(s.id) AS stalls,
                   COALESCE(SUM(s.verified_at IS NOT NULL), 0) AS verified,
                   COALESCE(SUM(s.place_status = 'holder'), 0) AS holder,
                   COALESCE(SUM(s.place_status = 'subtenant'), 0) AS subtenant,
                   COALESCE(SUM(s.place_status = 'no_place'), 0) AS no_place,
                   COALESCE(SUM(s.merchant_type = 'retail'), 0) AS retail,
                   COALESCE(SUM(s.merchant_type = 'wholesale'), 0) AS wholesale";
        $marketFilter = $marketId === null ? '' : ' AND s.market_id = ?';
        $params = $marketId === null ? [] : [$marketId];

        $byMarket = Database::all(
            "SELECT m.id, m.name, $counts FROM markets m
             LEFT JOIN stalls s ON s.market_id = m.id AND s.status = 'active'$marketFilter
             WHERE m.active = 1 GROUP BY m.id, m.name ORDER BY m.sort_order, m.id",
            $params
        );
        $byAisle = Database::all(
            "SELECT a.id, a.name, $counts FROM aisles a
             LEFT JOIN stalls s ON s.aisle_id = a.id AND s.status = 'active'$marketFilter
             GROUP BY a.id, a.name ORDER BY a.sort_order, a.id",
            $params
        );
        $shape = static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'stalls' => (int) $r['stalls'],
            'verified' => (int) $r['verified'],
            'place' => ['holder' => (int) $r['holder'], 'subtenant' => (int) $r['subtenant'], 'no_place' => (int) $r['no_place']],
            'type' => ['retail' => (int) $r['retail'], 'wholesale' => (int) $r['wholesale']],
        ];
        $markets = array_map($shape, $byMarket);
        if ($marketId !== null) {
            $markets = array_values(array_filter($markets, static fn (array $m): bool => $m['id'] === $marketId));
        }

        $totals = ['stalls' => 0, 'verified' => 0, 'place' => ['holder' => 0, 'subtenant' => 0, 'no_place' => 0], 'type' => ['retail' => 0, 'wholesale' => 0]];
        foreach ($markets as $m) {
            $totals['stalls'] += $m['stalls'];
            $totals['verified'] += $m['verified'];
            foreach (['place', 'type'] as $group) {
                foreach ($m[$group] as $key => $value) {
                    $totals[$group][$key] += $value;
                }
            }
        }
        return ['totals' => $totals, 'by_market' => $markets, 'by_aisle' => array_map($shape, $byAisle)];
    }

    /** Prix médians par produit de référence et par site (prix le plus bas de chaque étal) */
    public static function prices(?int $marketId, ?int $aisleId, ?int $catalogItemId): array
    {
        $where = ["p.status = 'active'", "s.status = 'active'", 'p.catalog_item_id IS NOT NULL'];
        $params = [];
        foreach (['s.market_id' => $marketId, 'ci.aisle_id' => $aisleId, 'p.catalog_item_id' => $catalogItemId] as $column => $value) {
            if ($value !== null) {
                $where[] = "$column = ?";
                $params[] = $value;
            }
        }
        $rows = Database::all(
            'SELECT ci.id AS item_id, ci.name AS item_name, ci.unit, s.market_id, m.name AS market_name, s.id AS stall_id, MIN(p.price) AS price
             FROM products p
             JOIN stalls s ON s.id = p.stall_id
             JOIN markets m ON m.id = s.market_id
             JOIN catalog_items ci ON ci.id = p.catalog_item_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY ci.id, ci.name, ci.unit, s.market_id, m.name, s.id',
            $params
        );

        $groups = [];
        foreach ($rows as $r) {
            $key = $r['item_id'] . '-' . $r['market_id'];
            $groups[$key] ??= [
                'item' => ['id' => (int) $r['item_id'], 'name' => $r['item_name'], 'unit' => $r['unit']],
                'market' => ['id' => (int) $r['market_id'], 'name' => $r['market_name']],
                'prices' => [],
            ];
            $groups[$key]['prices'][] = (int) $r['price'];
        }

        $items = [];
        $suppressed = 0;
        foreach ($groups as $g) {
            if (count($g['prices']) < self::minGroup()) {
                $suppressed++;
                continue;
            }
            sort($g['prices']);
            $items[] = [
                'item' => $g['item'],
                'market' => $g['market'],
                'median' => self::median($g['prices']),
                'min' => $g['prices'][0],
                'max' => $g['prices'][count($g['prices']) - 1],
                'stalls' => count($g['prices']),
            ];
        }
        usort($items, static fn (array $a, array $b): int => [$a['item']['name'], $a['market']['name']] <=> [$b['item']['name'], $b['market']['name']]);
        return ['items' => $items, 'suppressed_groups' => $suppressed, 'min_group_size' => self::minGroup()];
    }

    /** Volumes de ventes payées par semaine, site et allée */
    public static function trends(int $weeks, ?int $marketId): array
    {
        $params = [Clock::now(-$weeks * 7 * 86400)];
        $marketFilter = '';
        if ($marketId !== null) {
            $marketFilter = ' AND s.market_id = ?';
            $params[] = $marketId;
        }
        $rows = Database::all(
            "SELECT DATE_SUB(DATE(o.paid_at), INTERVAL WEEKDAY(o.paid_at) DAY) AS week_start,
                    s.market_id, m.name AS market_name, s.aisle_id, a.name AS aisle_name,
                    COUNT(*) AS orders, SUM(so.subtotal) AS amount, COUNT(DISTINCT so.stall_id) AS stalls
             FROM stall_orders so
             JOIN orders o ON o.id = so.order_id
             JOIN stalls s ON s.id = so.stall_id
             JOIN markets m ON m.id = s.market_id
             JOIN aisles a ON a.id = s.aisle_id
             WHERE so.status IN ('new', 'confirmed', 'ready', 'received') AND o.paid_at >= ?$marketFilter
             GROUP BY week_start, s.market_id, m.name, s.aisle_id, a.name
             ORDER BY week_start, m.name, a.name",
            $params
        );
        return self::suppress($rows, static fn (array $r): array => [
            'week_start' => $r['week_start'],
            'market' => ['id' => (int) $r['market_id'], 'name' => $r['market_name']],
            'aisle' => ['id' => (int) $r['aisle_id'], 'name' => $r['aisle_name']],
            'orders' => (int) $r['orders'],
            'amount' => (int) $r['amount'],
            'stalls' => (int) $r['stalls'],
        ]) + ['weeks' => $weeks];
    }

    /** Crédits demandés et accordés, par site et allée */
    public static function credits(): array
    {
        $rows = Database::all(
            "SELECT s.market_id, m.name AS market_name, s.aisle_id, a.name AS aisle_name,
                    COUNT(*) AS requests,
                    COALESCE(SUM(cr.status IN ('approved', 'repaid')), 0) AS approved,
                    COALESCE(SUM(CASE WHEN cr.status IN ('approved', 'repaid') THEN cr.approved_amount END), 0) AS approved_amount,
                    COALESCE(SUM(cr.status = 'repaid'), 0) AS repaid,
                    COUNT(DISTINCT cr.stall_id) AS stalls
             FROM credit_requests cr
             JOIN stalls s ON s.id = cr.stall_id
             JOIN markets m ON m.id = s.market_id
             JOIN aisles a ON a.id = s.aisle_id
             GROUP BY s.market_id, m.name, s.aisle_id, a.name
             ORDER BY m.name, a.name"
        );
        return self::suppress($rows, static fn (array $r): array => [
            'market' => ['id' => (int) $r['market_id'], 'name' => $r['market_name']],
            'aisle' => ['id' => (int) $r['aisle_id'], 'name' => $r['aisle_name']],
            'requests' => (int) $r['requests'],
            'approved' => (int) $r['approved'],
            'approved_amount' => (int) $r['approved_amount'],
            'repaid' => (int) $r['repaid'],
            'stalls' => (int) $r['stalls'],
        ]);
    }

    private static function suppress(array $rows, callable $shape): array
    {
        $visible = array_filter($rows, static fn (array $r): bool => (int) $r['stalls'] >= self::minGroup());
        return [
            'items' => array_values(array_map($shape, $visible)),
            'suppressed_groups' => count($rows) - count($visible),
            'min_group_size' => self::minGroup(),
        ];
    }

    private static function median(array $sorted): int
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $sorted[$mid] : (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }
}
