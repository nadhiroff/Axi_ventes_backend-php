<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Url;

final class Promotion
{
    /**
     * Promotions en cours pour un emplacement.
     * $audience : market_id, aisle_id, merchant_type (ciblage des campagnes Axi Pro)
     */
    public static function live(string $placement, array $audience = [], int $limit = 3): array
    {
        $where = ["placement = ?", "status = 'active'", 'starts_at <= ?', 'ends_at > ?'];
        $now = Clock::now();
        $params = [$placement, $now, $now];
        foreach (['market_id', 'aisle_id', 'merchant_type'] as $key) {
            $column = 'audience_' . $key;
            if (isset($audience[$key])) {
                $where[] = "($column IS NULL OR $column = ?)";
                $params[] = $audience[$key];
            } else {
                $where[] = "$column IS NULL";
            }
        }
        $rows = Database::all(
            'SELECT id, sponsor_name, title, body, image_path, target_type, target_id, target_url FROM promotions
             WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT $limit",
            $params
        );
        if ($rows) {
            $ids = array_column($rows, 'id');
            Database::run('UPDATE promotions SET impressions = impressions + 1 WHERE id IN (' . Database::in($ids) . ')', $ids);
        }
        return array_map([self::class, 'present'], $rows);
    }

    public static function click(int $id): ?array
    {
        $row = Database::one('SELECT id, sponsor_name, title, body, image_path, target_type, target_id, target_url FROM promotions WHERE id = ?', [$id]);
        if ($row !== null) {
            Database::run('UPDATE promotions SET clicks = clicks + 1 WHERE id = ?', [$id]);
        }
        return $row === null ? null : self::present($row);
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'sponsored' => true,
            'sponsor' => $r['sponsor_name'],
            'title' => $r['title'],
            'body' => $r['body'],
            'image_url' => Url::asset($r['image_path']),
            'target' => [
                'type' => $r['target_type'],
                'id' => $r['target_id'] === null ? null : (int) $r['target_id'],
                'url' => $r['target_url'],
            ],
        ];
    }
}
