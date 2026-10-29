<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Page;
use Axi\Core\Url;

final class Stall
{
    /** Colonnes de la fiche résumée d'un étal (listes, accueil, panier) */
    public const SUMMARY = 's.id, s.name, s.photo_path, s.row_label, s.stall_number, s.merchant_type, s.verified_at, s.moved_at,
        s.market_id, m.name AS market_name, s.aisle_id, a.name AS aisle_name';
    public const JOINS = 'FROM stalls s JOIN markets m ON m.id = s.market_id JOIN aisles a ON a.id = s.aisle_id';

    /** Champs modifiables par le commerçant (l'emplacement passe par le déménagement) */
    public const EDITABLE = ['name', 'description', 'contact_phone', 'whatsapp', 'merchant_type', 'place_status', 'aisle_id'];

    public static function findPublic(int $id): ?array
    {
        return Database::one(
            'SELECT ' . self::SUMMARY . ', s.description, s.contact_phone, s.whatsapp, u.name AS merchant_name
             ' . self::JOINS . ' JOIN users u ON u.id = s.merchant_id
             WHERE s.id = ? AND s.status = \'active\'',
            [$id]
        );
    }

    /** Étal complet du commerçant connecté */
    public static function findByMerchant(int $merchantId): ?array
    {
        return Database::one(
            'SELECT ' . self::SUMMARY . ', s.merchant_id, s.description, s.contact_phone, s.whatsapp, s.place_status, s.status
             ' . self::JOINS . ' WHERE s.merchant_id = ?',
            [$merchantId]
        );
    }

    public static function findRaw(int $id): ?array
    {
        return Database::one('SELECT * FROM stalls WHERE id = ?', [$id]);
    }

    public static function listByAisle(int $aisleId, array $filters, Page $page): array
    {
        $where = ["s.aisle_id = ?", "s.status = 'active'"];
        $params = [$aisleId];
        if (isset($filters['market_id'])) {
            $where[] = 's.market_id = ?';
            $params[] = $filters['market_id'];
        }
        if (isset($filters['type'])) {
            $where[] = 's.merchant_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['verified'])) {
            $where[] = 's.verified_at IS NOT NULL';
        }
        if (isset($filters['q'])) {
            $where[] = 's.name LIKE ?';
            $params[] = '%' . self::escapeLike($filters['q']) . '%';
        }
        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::value('SELECT COUNT(*) FROM stalls s' . $sqlWhere, $params);
        $rows = Database::all(
            'SELECT ' . self::SUMMARY . ' ' . self::JOINS . $sqlWhere . ' ORDER BY s.verified_at IS NULL, s.name' . $page->limitSql(),
            $params
        );
        return [array_map([self::class, 'present'], $rows), $total];
    }

    public static function search(string $q, ?int $marketId, int $limit): array
    {
        $params = ['%' . self::escapeLike($q) . '%'];
        $marketFilter = '';
        if ($marketId !== null) {
            $marketFilter = ' AND s.market_id = ?';
            $params[] = $marketId;
        }
        $rows = Database::all(
            'SELECT ' . self::SUMMARY . ' ' . self::JOINS . " WHERE s.status = 'active' AND s.name LIKE ?$marketFilter ORDER BY s.name LIMIT $limit",
            $params
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function followersCount(int $stallId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM follows WHERE stall_id = ?', [$stallId]);
    }

    public static function lastMove(int $stallId): ?array
    {
        $row = Database::one(
            'SELECT mv.created_at, mv.to_row, mv.to_number, mv.recipients_count, m.name AS market_name
             FROM stall_moves mv JOIN markets m ON m.id = mv.to_market_id
             WHERE mv.stall_id = ? ORDER BY mv.id DESC LIMIT 1',
            [$stallId]
        );
        return $row === null ? null : [
            'at' => Clock::iso($row['created_at']),
            'location' => self::locationLabel($row['market_name'], $row['to_row'], $row['to_number']),
            'recipients' => (int) $row['recipients_count'],
        ];
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'photo_url' => Url::asset($r['photo_path']),
            'market' => ['id' => (int) $r['market_id'], 'name' => $r['market_name']],
            'aisle' => ['id' => (int) $r['aisle_id'], 'name' => $r['aisle_name']],
            'location' => [
                'row' => $r['row_label'],
                'number' => $r['stall_number'],
                'label' => self::locationLabel($r['market_name'], $r['row_label'], $r['stall_number']),
            ],
            'merchant_type' => $r['merchant_type'],
            'verified' => $r['verified_at'] !== null,
            // L'agent Axi a confirmé l'emplacement actuel (après le dernier déménagement)
            'location_confirmed' => $r['verified_at'] !== null && ($r['moved_at'] === null || $r['verified_at'] >= $r['moved_at']),
            'moved_at' => Clock::iso($r['moved_at']),
        ];
    }

    /** « Tokpa Yôyô, rangée B, n°12 » */
    public static function locationLabel(string $market, ?string $row, ?string $number): string
    {
        $parts = [$market];
        if ($row !== null && $row !== '') {
            $parts[] = 'rangée ' . $row;
        }
        if ($number !== null && $number !== '') {
            $parts[] = 'n°' . $number;
        }
        return implode(', ', $parts);
    }

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
