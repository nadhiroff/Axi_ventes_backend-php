<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\Page;

/** Fichier clients d'un commerçant (table merchant_customers) */
final class Customer
{
    public const EDITABLE = ['name', 'customer_type', 'city', 'note'];

    /**
     * Filtres : type (retail|wholesale), segment (loyal|inactive|new), q (nom ou numéro), ids.
     * Renvoie [clause WHERE, paramètres].
     */
    public static function where(int $stallId, array $filters): array
    {
        $where = ['c.stall_id = ?'];
        $params = [$stallId];
        if (isset($filters['type'])) {
            $where[] = 'c.customer_type = ?';
            $params[] = $filters['type'];
        }
        $segment = $filters['segment'] ?? null;
        if ($segment === 'loyal') {
            $where[] = 'c.orders_count >= ?';
            $params[] = (int) Config::get('customers.loyal_min_orders');
        } elseif ($segment === 'inactive') {
            $where[] = '(c.last_order_at IS NULL OR c.last_order_at < ?)';
            $params[] = Clock::now(-86400 * (int) Config::get('customers.inactive_days'));
        } elseif ($segment === 'new') {
            $where[] = 'c.created_at >= ?';
            $params[] = Clock::now(-86400 * 30);
        }
        if (isset($filters['q'])) {
            $where[] = '(c.name LIKE ? OR c.phone LIKE ?)';
            $like = '%' . Stall::escapeLike($filters['q']) . '%';
            array_push($params, $like, $like);
        }
        if (isset($filters['ids'])) {
            $where[] = 'c.id IN (' . Database::in($filters['ids']) . ')';
            array_push($params, ...$filters['ids']);
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    public static function list(int $stallId, array $filters, Page $page): array
    {
        [$where, $params] = self::where($stallId, $filters);
        $total = (int) Database::value('SELECT COUNT(*) FROM merchant_customers c' . $where, $params);
        $rows = Database::all(
            'SELECT c.* FROM merchant_customers c' . $where . ' ORDER BY c.last_order_at IS NULL, c.last_order_at DESC, c.name' . $page->limitSql(),
            $params
        );
        return [array_map([self::class, 'present'], $rows), $total];
    }

    /** Compteurs pour les filtres de l'écran « Mes clients » */
    public static function counts(int $stallId): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(customer_type = 'retail'), 0) AS retail,
                    COALESCE(SUM(customer_type = 'wholesale'), 0) AS wholesale,
                    COALESCE(SUM(orders_count >= ?), 0) AS loyal
             FROM merchant_customers WHERE stall_id = ?",
            [(int) Config::get('customers.loyal_min_orders'), $stallId]
        );
        return array_map('intval', $row);
    }

    public static function find(int $id, int $stallId): ?array
    {
        return Database::one('SELECT * FROM merchant_customers WHERE id = ? AND stall_id = ?', [$id, $stallId]);
    }

    public static function findByPhone(int $stallId, string $phone): ?array
    {
        return Database::one('SELECT * FROM merchant_customers WHERE stall_id = ? AND phone = ?', [$stallId, $phone]);
    }

    public static function create(int $stallId, ?int $userId, string $name, string $phone, string $type, ?string $city, ?string $note): int
    {
        return Database::insert(
            'INSERT INTO merchant_customers (stall_id, user_id, name, phone, customer_type, city, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$stallId, $userId, $name, $phone, $type, $city, $note, Clock::now()]
        );
    }

    /** Après un paiement : ajoute le client au fichier du commerçant ou met à jour ses compteurs */
    public static function recordOrder(int $stallId, array $client, int $amount): void
    {
        $existing = self::findByPhone($stallId, $client['phone']);
        if ($existing === null) {
            $id = self::create($stallId, (int) $client['id'], $client['name'], $client['phone'], 'retail', null, null);
        } else {
            $id = (int) $existing['id'];
        }
        Database::run(
            'UPDATE merchant_customers SET user_id = COALESCE(user_id, ?), orders_count = orders_count + 1, total_spent = total_spent + ?, last_order_at = ? WHERE id = ?',
            [(int) $client['id'], $amount, Clock::now(), $id]
        );
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'phone' => $r['phone'],
            'type' => $r['customer_type'],
            'city' => $r['city'],
            'note' => $r['note'],
            'orders_count' => (int) $r['orders_count'],
            'total_spent' => (int) $r['total_spent'],
            'last_order_at' => Clock::iso($r['last_order_at']),
            'is_axi_user' => $r['user_id'] !== null,
            'loyal' => (int) $r['orders_count'] >= (int) Config::get('customers.loyal_min_orders'),
        ];
    }
}
