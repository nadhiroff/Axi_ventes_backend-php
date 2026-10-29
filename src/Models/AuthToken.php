<?php
declare(strict_types=1);

namespace Axi\Models;

use Axi\Core\Clock;
use Axi\Core\Database;

final class AuthToken
{
    public static function create(int $userId, string $tokenHash, ?string $device, string $expiresAt): void
    {
        Database::run(
            'INSERT INTO auth_tokens (user_id, token_hash, device, expires_at, last_used_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $tokenHash, $device, $expiresAt, Clock::now(), Clock::now()]
        );
    }

    /** Jeton valide + utilisateur associé, en une seule requête. */
    public static function findActive(string $tokenHash, string $now): ?array
    {
        return Database::run(
            'SELECT t.id AS token_id, u.id, u.phone, u.name, u.role, u.lang, u.status
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > ?
             LIMIT 1',
            [$tokenHash, $now]
        )->fetch() ?: null;
    }

    /** Prolonge la session, au plus une écriture par heure pour limiter la charge. */
    public static function touch(int $id, int $ttlSeconds): void
    {
        Database::run(
            'UPDATE auth_tokens SET last_used_at = ?, expires_at = ? WHERE id = ? AND last_used_at < ?',
            [Clock::now(), Clock::now($ttlSeconds), $id, Clock::now(-3600)]
        );
    }

    public static function revoke(int $id): void
    {
        Database::run('UPDATE auth_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::now(), $id]);
    }
}
