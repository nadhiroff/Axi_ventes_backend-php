<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Database;

final class User
{
    private const COLUMNS = 'id, phone, name, role, lang, status, created_at';

    public static function findById(int $id): ?array
    {
        return Database::run('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ? LIMIT 1', [$id])->fetch() ?: null;
    }

    public static function findByPhone(string $phone): ?array
    {
        return Database::run('SELECT ' . self::COLUMNS . ' FROM users WHERE phone = ? LIMIT 1', [$phone])->fetch() ?: null;
    }

    public static function create(string $phone, string $name, string $role, string $lang): int
    {
        Database::run('INSERT INTO users (phone, name, role, lang) VALUES (?, ?, ?, ?)', [$phone, $name, $role, $lang]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Champs renvoyés à l'application mobile */
    public static function toPublic(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'lang' => $user['lang'],
        ];
    }
}
