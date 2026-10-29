<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Database;

final class Aisle
{
    /** Allées avec le nombre d'étals actifs, éventuellement limité à un marché */
    public static function allWithCounts(?int $marketId = null): array
    {
        $params = [];
        $marketFilter = '';
        if ($marketId !== null) {
            $marketFilter = ' AND s.market_id = ?';
            $params[] = $marketId;
        }
        $rows = Database::all(
            "SELECT a.id, a.slug, a.name, a.icon, COUNT(s.id) AS stalls_count
             FROM aisles a LEFT JOIN stalls s ON s.aisle_id = a.id AND s.status = 'active'$marketFilter
             GROUP BY a.id, a.slug, a.name, a.icon, a.sort_order
             ORDER BY a.sort_order, a.id",
            $params
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'icon' => $r['icon'],
            'stalls_count' => (int) $r['stalls_count'],
        ], $rows);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT id, slug, name, icon FROM aisles WHERE id = ?', [$id]);
    }
}
