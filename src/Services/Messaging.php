<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;

/**
 * Règle de conservation des messages client ↔ commerçant :
 * - le client NE suit PAS l'étal : chaque message disparaît « message_ttl_hours » (24 h) après son envoi ;
 * - le client suit l'étal : les messages sont gardés ; s'il arrête de suivre, ils disparaissent 24 h plus tard.
 */
final class Messaging
{
    /** Condition SQL d'un message encore visible (alias m) */
    public const VISIBLE = '(m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())';

    public static function ttlHours(): int
    {
        return max(1, Settings::int('message_ttl_hours'));
    }

    public static function follows(int $clientId, int $stallId): bool
    {
        return Database::value('SELECT 1 FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$clientId, $stallId]) !== null;
    }

    /** Date d'effacement d'un nouveau message (null = gardé) */
    public static function expiresAt(int $clientId, int $stallId): ?string
    {
        return self::follows($clientId, $stallId) ? null : Clock::now(self::ttlHours() * 3600);
    }

    /** Le client suit l'étal : les messages encore visibles sont gardés */
    public static function keep(int $clientId, int $stallId): void
    {
        Database::run(
            'UPDATE messages m JOIN conversations c ON c.id = m.conversation_id SET m.expires_at = NULL
             WHERE c.client_id = ? AND c.stall_id = ? AND m.expires_at > UTC_TIMESTAMP()',
            [$clientId, $stallId],
        );
    }

    /** Le client ne suit plus l'étal : les messages gardés disparaîtront dans 24 h */
    public static function release(int $clientId, int $stallId): void
    {
        Database::run(
            'UPDATE messages m JOIN conversations c ON c.id = m.conversation_id SET m.expires_at = ?
             WHERE c.client_id = ? AND c.stall_id = ? AND m.expires_at IS NULL',
            [Clock::now(self::ttlHours() * 3600), $clientId, $stallId],
        );
    }

    /** Ménage (tâche planifiée) : messages expirés et leurs enregistrements vocaux. @return int messages effacés */
    public static function purgeExpired(): int
    {
        $total = 0;
        do {
            $rows = Database::all('SELECT id, kind, file_path FROM messages WHERE expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP() LIMIT 1000');
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                if ($r['kind'] === 'voice' && $r['file_path'] !== null) {
                    @unlink(BASE_PATH . '/storage/' . $r['file_path']);
                }
            }
            $ids = array_column($rows, 'id');
            $total += Database::run('DELETE FROM messages WHERE id IN (' . Database::in($ids) . ')', $ids)->rowCount();
        } while (count($rows) === 1000);
        return $total;
    }
}
