<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Database;

final class OtpCode
{
    public static function latestForPhone(string $phone): ?array
    {
        return Database::run(
            'SELECT id, code_hash, attempts, expires_at, used_at, created_at FROM otp_codes WHERE phone = ? ORDER BY id DESC LIMIT 1',
            [$phone]
        )->fetch() ?: null;
    }

    public static function countSince(string $phone, string $since): int
    {
        return (int) Database::run('SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at >= ?', [$phone, $since])->fetchColumn();
    }

    /** Enregistre un nouveau code et invalide les précédents : un seul code valide à la fois. */
    public static function create(string $phone, string $codeHash, string $now, string $expiresAt): void
    {
        Database::run('UPDATE otp_codes SET used_at = ? WHERE phone = ? AND used_at IS NULL', [$now, $phone]);
        Database::run(
            'INSERT INTO otp_codes (phone, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [$phone, $codeHash, $expiresAt, $now]
        );
    }

    public static function incrementAttempts(int $id): void
    {
        Database::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$id]);
    }

    /** Marque le code comme utilisé. Renvoie false s'il l'a déjà été (requêtes simultanées). */
    public static function consume(int $id, string $now): bool
    {
        return Database::run('UPDATE otp_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, $id])->rowCount() === 1;
    }
}
