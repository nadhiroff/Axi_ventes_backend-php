<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;

final class CartItem
{
    /** Lignes du panier avec produit et étal (y compris devenus indisponibles) */
    public static function lines(int $userId): array
    {
        return Database::all(
            "SELECT c.product_id, c.quantity, p.name, p.unit, p.price, p.wholesale_price, p.wholesale_min_qty, p.photo_path,
                    p.stock_status, p.status AS product_status, s.id AS stall_id, s.name AS stall_name, s.status AS stall_status,
                    s.verified_at, m.name AS market_name
             FROM cart_items c
             JOIN products p ON p.id = c.product_id
             JOIN stalls s ON s.id = p.stall_id
             JOIN markets m ON m.id = s.market_id
             WHERE c.user_id = ?
             ORDER BY s.id, c.created_at",
            [$userId]
        );
    }

    public static function set(int $userId, int $productId, int $quantity): void
    {
        Database::run(
            'INSERT INTO cart_items (user_id, product_id, quantity, created_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = ?',
            [$userId, $productId, $quantity, Clock::now(), $quantity]
        );
    }

    public static function quantity(int $userId, int $productId): int
    {
        return (int) Database::value('SELECT quantity FROM cart_items WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
    }

    public static function remove(int $userId, int $productId): void
    {
        Database::run('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
    }

    public static function clear(int $userId): void
    {
        Database::run('DELETE FROM cart_items WHERE user_id = ?', [$userId]);
    }
}
