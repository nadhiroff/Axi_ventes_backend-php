<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Database;

final class Market
{
    public static function all(): array
    {
        return array_map(
            [self::class, 'present'],
            Database::all('SELECT id, slug, name, city FROM markets WHERE active = 1 ORDER BY sort_order, id')
        );
    }

    public static function exists(int $id): bool
    {
        return Database::value('SELECT 1 FROM markets WHERE id = ? AND active = 1', [$id]) !== null;
    }

    public static function present(array $r): array
    {
        return ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'city' => $r['city']];
    }
}
