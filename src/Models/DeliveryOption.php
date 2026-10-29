<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Database;

final class DeliveryOption
{
    public static function all(): array
    {
        return array_map(
            [self::class, 'present'],
            Database::all('SELECT * FROM delivery_options WHERE active = 1 ORDER BY sort_order, id')
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM delivery_options WHERE id = ? AND active = 1', [$id]);
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'mode' => $r['mode'],
            'label' => $r['label'],
            'city' => $r['city'],
            'address' => $r['address'],
            'fee' => (int) $r['fee'],
            'eta' => $r['eta_label'],
        ];
    }
}
