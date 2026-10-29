<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;

final class Follow
{
    public static function follow(int $userId, int $stallId): void
    {
        Database::run('INSERT IGNORE INTO follows (user_id, stall_id, created_at) VALUES (?, ?, ?)', [$userId, $stallId, Clock::now()]);
    }

    public static function unfollow(int $userId, int $stallId): void
    {
        Database::run('DELETE FROM follows WHERE user_id = ? AND stall_id = ?', [$userId, $stallId]);
    }

    public static function isFollowing(int $userId, int $stallId): bool
    {
        return Database::value('SELECT 1 FROM follows WHERE user_id = ? AND stall_id = ?', [$userId, $stallId]) !== null;
    }

    /** Étals suivis, les derniers déménagés en premier */
    public static function stallsFor(int $userId, int $limit = 20): array
    {
        $rows = Database::all(
            'SELECT ' . Stall::SUMMARY . ' ' . Stall::JOINS . " JOIN follows f ON f.stall_id = s.id
             WHERE f.user_id = ? AND s.status = 'active'
             ORDER BY s.moved_at IS NULL, s.moved_at DESC, f.created_at DESC LIMIT $limit",
            [$userId]
        );
        return array_map([Stall::class, 'present'], $rows);
    }
}
