<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Ids;
use Axi\Core\Url;

/** Lecture des étals et produits, et mise en forme exacte du contrat (StallSummary, StallDetail, Product). */
final class Stalls
{
    private const NEW_PRODUCT_DAYS = 3;

    /** Produit visible des clients : publié et pas épuisé (stock non suivi ou > 0). Alias de table : p */
    public const VISIBLE = "p.status = 'active' AND (p.stock_quantity IS NULL OR p.stock_quantity > 0)";

    /**
     * Étals actifs (compte du commerçant actif) avec leur site. $where et $suffix sont écrits dans le code.
     */
    public static function query(string $where = '1', array $params = [], string $suffix = '', bool $includeInactive = false): array
    {
        $sql = 'SELECT s.*, IF(s.site_id = \'autre\' AND s.site_other IS NOT NULL, s.site_other, si.name) AS site_name, si.map_url AS site_map_url, u.name AS owner_name, u.status AS owner_status,
                  EXISTS (SELECT 1 FROM products p WHERE p.stall_id = s.id AND ' . self::VISIBLE . ' AND p.created_at > ?) AS has_new
                FROM stalls s
                JOIN sites si ON si.id = s.site_id
                JOIN users u ON u.id = s.user_id
                WHERE ' . ($includeInactive ? '1' : 's.status = \'active\' AND u.status = \'active\'') . ' AND (' . $where . ') ' . $suffix;
        return Database::all($sql, [Clock::now(-86400 * self::NEW_PRODUCT_DAYS), ...$params]);
    }

    public static function findActive(int $id): ?array
    {
        return self::query('s.id = ?', [$id])[0] ?? null;
    }

    public static function findByUser(int $userId): ?array
    {
        return self::query('s.user_id = ?', [$userId], '', true)[0] ?? null;
    }

    public static function image(?string $path, ?int $width, ?int $height): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }
        return [
            'thumb_url' => Url::asset($path . '/thumb.webp'),
            'medium_url' => Url::asset($path . '/medium.webp'),
            'width' => (int) $width,
            'height' => (int) $height,
            'blurhash' => null,
        ];
    }

    public static function summary(array $s): array
    {
        return [
            'id' => Ids::encode('stl', $s['id']),
            'name' => $s['name'],
            'tagline' => $s['tagline'],
            'avatar' => self::image($s['photo_path'], $s['photo_width'] === null ? null : (int) $s['photo_width'], $s['photo_height'] === null ? null : (int) $s['photo_height']),
            'aisle_id' => $s['aisle_id'],
            'verified' => $s['verified_at'] !== null,
            'is_open' => (bool) $s['is_open'],
            'location' => self::location($s),
            'has_new_products' => (bool) $s['has_new'],
            'merchant_type' => $s['merchant_type'],
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
            'site_id' => $s['site_id'],
            'row' => $s['row_label'],
            'stand' => $s['stand'],
            'label' => $s['site_name'] . ($where !== null ? ' · ' . $where : ''),
            'map_url' => $s['map_url'] ?? $s['site_map_url'],
            'moved_at' => Clock::iso($s['moved_at']),
        ];
    }

    /** Produits publiés d'un étal : « Disponible aujourd'hui » en premier, puis du plus récent au plus ancien */
    public static function products(int $stallId, int $offset = 0, ?int $limit = null, bool $withDrafts = false): array
    {
        // Côté commerçant ($withDrafts) : brouillons, produits masqués et épuisés aussi
        $sql = 'SELECT p.* FROM products p WHERE p.stall_id = ? AND ' . ($withDrafts ? 'p.status IN (\'active\', \'draft\', \'paused\')' : self::VISIBLE)
            . ' ORDER BY p.status = \'draft\' DESC, p.is_today DESC, p.updated_at DESC, p.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
        }
        return Database::all($sql, [$stallId]);
    }

    /** Photos de plusieurs produits en une requête : [product_id => [image, …]] */
    public static function imagesFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        foreach (Database::all('SELECT * FROM product_images WHERE product_id IN (' . Database::in($productIds) . ') ORDER BY position, id', $productIds) as $img) {
            $out[(int) $img['product_id']][] = self::image($img['path'], (int) $img['width'], (int) $img['height']);
        }
        return $out;
    }

    /** @param array|null $images photos déjà chargées (imagesFor), sinon lues en base */
    public static function product(array $p, ?array $images = null): array
    {
        $images ??= self::imagesFor([(int) $p['id']])[(int) $p['id']] ?? [];
        return [
            'id' => Ids::encode('prd', $p['id']),
            'name' => $p['name'],
            'image' => $images[0] ?? null,
            'images' => $images,
            'price' => $p['price_amount'] === null ? null : [
                'amount' => (int) $p['price_amount'],
                'amount_max' => $p['price_max'] === null ? null : (int) $p['price_max'],
                'currency' => 'XOF',
            ],
            'in_stock' => (bool) $p['in_stock'] && ($p['stock_quantity'] === null || (int) $p['stock_quantity'] > 0),
            'stock_quantity' => $p['stock_quantity'] === null ? null : (int) $p['stock_quantity'],
            'sold_out' => $p['stock_quantity'] !== null && (int) $p['stock_quantity'] === 0,
            'is_today' => (bool) $p['is_today'],
            'status' => $p['status'],
            'has_voice_note' => $p['audio_path'] !== null,
            'updated_at' => Clock::iso($p['updated_at']),
        ];
    }

    /** Liste de produits avec leurs photos (une seule requête pour les photos) */
    public static function productList(array $rows): array
    {
        $images = self::imagesFor(array_map(static fn (array $p): int => (int) $p['id'], $rows));
        return array_map(static fn (array $p): array => self::product($p, $images[(int) $p['id']] ?? []), $rows);
    }

    public static function aisle(array $a): array
    {
        return ['id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'], 'stall_count' => (int) $a['stall_count']];
    }

    /** Allées actives dans l'ordre d'affichage, avec le nombre d'étals actifs */
    public static function aisles(?string $id = null): array
    {
        $rows = Database::all(
            'SELECT a.*, (SELECT COUNT(*) FROM stalls s JOIN users u ON u.id = s.user_id WHERE s.aisle_id = a.id AND s.status = \'active\' AND u.status = \'active\') AS stall_count
             FROM aisles a WHERE a.active = 1' . ($id === null ? '' : ' AND a.id = ?') . ' ORDER BY a.sort_order, a.name',
            $id === null ? [] : [$id],
        );
        return array_map([self::class, 'aisle'], $rows);
    }
}
