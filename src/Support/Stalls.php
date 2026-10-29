<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\Ids;
use Axi\Core\Url;

/** Lecture des étals et produits, et mise en forme exacte du contrat (StallSummary, StallDetail, Product). */
final class Stalls
{
    /**
     * Étals actifs avec leur site ; $where et $suffix sont écrits dans le code (jamais par l'utilisateur).
     */
    public static function query(string $where = '1', array $params = [], string $suffix = ''): array
    {
        $sql = 'SELECT s.*, si.name AS site_name, si.map_url AS site_map_url, u.name AS owner_name,
                  EXISTS (SELECT 1 FROM products p WHERE p.stall_id = s.id AND p.status = \'active\' AND p.created_at > ?) AS has_new
                FROM stalls s
                JOIN sites si ON si.id = s.site_id
                JOIN users u ON u.id = s.user_id
                WHERE s.status = \'active\' AND (' . $where . ') ' . $suffix;
        return Database::all($sql, [Clock::now(-86400 * (int) Config::get('new_product_days', 3)), ...$params]);
    }

    public static function findActive(int $id): ?array
    {
        return self::query('s.id = ?', [$id])[0] ?? null;
    }

    public static function findByUser(int $userId): ?array
    {
        return self::query('s.user_id = ?', [$userId])[0] ?? null;
    }

    public static function summary(array $s): array
    {
        return [
            'id' => Ids::encode('stl', $s['id']),
            'name' => $s['name'],
            'tagline' => $s['tagline'],
            'avatar' => null,
            'aisle_id' => $s['aisle_id'],
            'verified' => $s['verified_at'] !== null,
            'is_open' => (bool) $s['is_open'],
            'location' => self::location($s),
            'has_new_products' => (bool) $s['has_new'],
        ];
    }

    public static function detail(array $s, ?int $viewerId): array
    {
        $followed = $viewerId !== null
            && Database::value('SELECT 1 FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$viewerId, $s['id']]) !== null;
        return self::summary($s) + [
            'owner_display_name' => $s['owner_name'],
            'verification' => $s['verified_at'] === null ? null : ['by' => 'agent', 'verified_at' => Clock::iso($s['verified_at'])],
            'pay_on_delivery' => (bool) $s['pay_on_delivery'],
            'delivery_note' => $s['delivery_note'],
            'contacts' => [
                'phone' => $s['phone'],
                'whatsapp' => $s['whatsapp'],
                'accepts_voice_messages' => (bool) $s['accepts_voice_messages'],
            ],
            'is_followed' => $followed,
        ];
    }

    public static function location(array $s): array
    {
        $parts = array_filter([$s['row_label'], $s['stand'] !== null && $s['stand'] !== '' ? 'stand ' . $s['stand'] : null]);
        $where = $parts ? implode(', ', $parts) : ($s['has_physical_place'] ? null : 'sans emplacement fixe');
        return [
            'site' => $s['site_name'],
            'row' => $s['row_label'],
            'stand' => $s['stand'],
            'label' => $s['site_name'] . ($where !== null ? ' · ' . $where : ''),
            'map_url' => $s['map_url'] ?? $s['site_map_url'],
            'moved_at' => Clock::iso($s['moved_at']),
        ];
    }

    /** Produits d'un étal : « Disponible aujourd'hui » en premier, puis du plus récent au plus ancien */
    public static function products(int $stallId, int $offset = 0, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM products WHERE stall_id = ? AND status = \'active\' ORDER BY is_today DESC, updated_at DESC, id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
        }
        return Database::all($sql, [$stallId]);
    }

    public static function product(array $p): array
    {
        return [
            'id' => Ids::encode('prd', $p['id']),
            'name' => $p['name'],
            'image' => $p['image_path'] === null ? null : [
                'thumb_url' => Url::asset($p['image_path'] . '/thumb.webp'),
                'medium_url' => Url::asset($p['image_path'] . '/medium.webp'),
                'width' => (int) $p['image_width'],
                'height' => (int) $p['image_height'],
                'blurhash' => null,
            ],
            'price' => $p['price_amount'] === null ? null : [
                'amount' => (int) $p['price_amount'],
                'amount_max' => $p['price_max'] === null ? null : (int) $p['price_max'],
                'currency' => 'XOF',
            ],
            'in_stock' => (bool) $p['in_stock'],
            'is_today' => (bool) $p['is_today'],
            'updated_at' => Clock::iso($p['updated_at']),
        ];
    }

    public static function aisle(array $a): array
    {
        return ['id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'], 'stall_count' => (int) $a['stall_count']];
    }

    /** Allées dans l'ordre d'affichage, avec le nombre d'étals actifs */
    public static function aisles(?string $id = null): array
    {
        $rows = Database::all(
            'SELECT a.*, (SELECT COUNT(*) FROM stalls s WHERE s.aisle_id = a.id AND s.status = \'active\') AS stall_count
             FROM aisles a' . ($id === null ? '' : ' WHERE a.id = ?') . ' ORDER BY a.sort_order, a.name',
            $id === null ? [] : [$id],
        );
        return array_map([self::class, 'aisle'], $rows);
    }
}
