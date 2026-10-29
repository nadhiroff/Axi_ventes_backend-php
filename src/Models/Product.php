<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Page;
use Axi\Core\Url;

final class Product
{
    public const COLUMNS = 'p.id, p.stall_id, p.catalog_item_id, p.name, p.description, p.unit, p.price, p.price_max,
        p.wholesale_price, p.wholesale_min_qty, p.photo_path, p.audio_path, p.stock_status, p.daily_date, p.status';

    /** Champs modifiables par le commerçant */
    public const EDITABLE = ['name', 'description', 'unit', 'price', 'price_max', 'wholesale_price', 'wholesale_min_qty', 'stock_status', 'catalog_item_id'];

    /** Produits du jour d'un étal */
    public static function todayForStall(int $stallId): array
    {
        $rows = Database::all(
            'SELECT ' . self::COLUMNS . " FROM products p WHERE p.stall_id = ? AND p.status = 'active' AND p.daily_date = ? ORDER BY p.updated_at DESC",
            [$stallId, Clock::localDate()]
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function listForStall(int $stallId, Page $page, bool $todayOnly = false): array
    {
        $where = " WHERE p.stall_id = ? AND p.status = 'active'";
        $params = [$stallId];
        if ($todayOnly) {
            $where .= ' AND p.daily_date = ?';
            $params[] = Clock::localDate();
        }
        $total = (int) Database::value('SELECT COUNT(*) FROM products p' . $where, $params);
        $rows = Database::all('SELECT ' . self::COLUMNS . ' FROM products p' . $where . ' ORDER BY p.daily_date DESC, p.updated_at DESC' . $page->limitSql(), $params);
        return [array_map([self::class, 'present'], $rows), $total];
    }

    public static function search(string $q, ?int $marketId, int $limit): array
    {
        $params = ['%' . Stall::escapeLike($q) . '%'];
        $marketFilter = '';
        if ($marketId !== null) {
            $marketFilter = ' AND s.market_id = ?';
            $params[] = $marketId;
        }
        $rows = Database::all(
            'SELECT ' . self::COLUMNS . ", s.name AS stall_name, m.name AS market_name
             FROM products p JOIN stalls s ON s.id = p.stall_id JOIN markets m ON m.id = s.market_id
             WHERE p.status = 'active' AND s.status = 'active' AND p.name LIKE ?$marketFilter
             ORDER BY p.daily_date DESC, p.price LIMIT $limit",
            $params
        );
        return array_map(static fn (array $r): array => self::present($r) + [
            'stall' => ['id' => (int) $r['stall_id'], 'name' => $r['stall_name'], 'market' => $r['market_name']],
        ], $rows);
    }

    public static function findForStall(int $id, int $stallId): ?array
    {
        return Database::one('SELECT ' . self::COLUMNS . " FROM products p WHERE p.id = ? AND p.stall_id = ? AND p.status = 'active'", [$id, $stallId]);
    }

    /** Produit commandable : actif, en stock et dans un étal actif */
    public static function findOrderable(int $id): ?array
    {
        return Database::one(
            'SELECT ' . self::COLUMNS . " FROM products p JOIN stalls s ON s.id = p.stall_id
             WHERE p.id = ? AND p.status = 'active' AND p.stock_status <> 'out' AND s.status = 'active'",
            [$id]
        );
    }

    public static function countForStall(int $stallId): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(daily_date = ?), 0) AS today FROM products WHERE stall_id = ? AND status = 'active'",
            [Clock::localDate(), $stallId]
        );
        return ['total' => (int) $row['total'], 'today' => (int) $row['today']];
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'unit' => $r['unit'],
            'price' => (int) $r['price'],
            'price_max' => $r['price_max'] === null ? null : (int) $r['price_max'],
            'wholesale_price' => $r['wholesale_price'] === null ? null : (int) $r['wholesale_price'],
            'wholesale_min_qty' => $r['wholesale_min_qty'] === null ? null : (int) $r['wholesale_min_qty'],
            'photo_url' => Url::asset($r['photo_path']),
            'audio_url' => Url::asset($r['audio_path']),
            'stock_status' => $r['stock_status'],
            'is_today' => $r['daily_date'] === Clock::localDate(),
            'catalog_item_id' => $r['catalog_item_id'] === null ? null : (int) $r['catalog_item_id'],
        ];
    }
}
