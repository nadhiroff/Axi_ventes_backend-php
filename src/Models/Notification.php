<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Page;

final class Notification
{
    public static function send(int $userId, string $type, string $title, string $body, array $data = []): void
    {
        self::sendMany([$userId], $type, $title, $body, $data);
    }

    public static function sendMany(array $userIds, string $type, string $title, string $body, array $data = []): void
    {
        if ($userIds === []) {
            return;
        }
        $json = $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = Clock::now();
        Database::insertMany(
            'notifications',
            ['user_id', 'type', 'title', 'body', 'data', 'created_at'],
            array_map(static fn (int $id): array => [$id, $type, $title, $body, $json, $now], $userIds)
        );
    }

    public static function list(int $userId, Page $page): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);
        $unread = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
        $rows = Database::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC' . $page->limitSql(), [$userId]);
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'title' => $r['title'],
            'body' => $r['body'],
            'data' => $r['data'] === null ? null : json_decode($r['data'], true),
            'read' => $r['read_at'] !== null,
            'created_at' => Clock::iso($r['created_at']),
        ], $rows);
        return [$items, $total, $unread];
    }

    public static function markRead(int $userId, ?array $ids): void
    {
        if ($ids === null) {
            Database::run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::now(), $userId]);
            return;
        }
        if ($ids !== []) {
            Database::run(
                'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN (' . Database::in($ids) . ')',
                [Clock::now(), $userId, ...$ids]
            );
        }
    }
}
