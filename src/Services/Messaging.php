<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;

/**
 * Durée des messages client ↔ commerçant, réglée par le commerçant pour chaque discussion :
 * 24 h par défaut pour tout le monde, 48 h au plus sans abonnement, 30 ou 90 jours pour un commerçant abonné.
 * Si l'abonnement s'arrête, les nouveaux messages repassent à 48 h au plus.
 */
final class Messaging
{
    /** Condition SQL d'un message encore visible (alias m) */
    public const VISIBLE = '(m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())';

    /** Durées proposées (heures) => réservée aux abonnés */
    public const RETENTION = [24 => false, 48 => false, 720 => true, 2160 => true];
    public const FREE_MAX_HOURS = 48;

    /** Durée par défaut d'une nouvelle discussion (réglage Axi « message_ttl_hours », 24 h) */
    public static function ttlHours(): int
    {
        return max(1, Settings::int('message_ttl_hours'));
    }

    /** Durée appliquée aux nouveaux messages : celle choisie, plafonnée à 48 h sans abonnement */
    public static function effectiveHours(array $conversation): int
    {
        $hours = (int) ($conversation['retention_hours'] ?? self::ttlHours());
        return $hours > self::FREE_MAX_HOURS && !\Axi\Support\Subscription::active((int) $conversation['stall_id']) ? self::FREE_MAX_HOURS : $hours;
    }

    /** Date d'effacement d'un nouveau message de cette discussion */
    public static function expiresAt(array $conversation): string
    {
        return Clock::now(self::effectiveHours($conversation) * 3600);
    }

    /** « 24 h », « 48 h », « 30 jours », « 90 jours » */
    public static function label(int $hours): string
    {
        return $hours < 72 ? "$hours h" : intdiv($hours, 24) . ' jours';
    }

    /** Nouvelle durée : les messages encore visibles suivent la nouvelle règle (comptée depuis leur envoi) */
    public static function setRetention(int $conversationId, int $hours): void
    {
        Database::transaction(static function () use ($conversationId, $hours): void {
            Database::run('UPDATE conversations SET retention_hours = ? WHERE id = ?', [$hours, $conversationId]);
            Database::run(
                'UPDATE messages SET expires_at = DATE_ADD(created_at, INTERVAL ? HOUR)
                 WHERE conversation_id = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
                [$hours, $conversationId],
            );
        });
    }

    /** Le client a accepté d'être dans le fichier du commerçant (ou y est entré en suivant l'étal ou en commandant) */
    public static function accepted(int $clientId, int $stallId): bool
    {
        return Database::value('SELECT 1 FROM merchant_clients WHERE user_id = ? AND stall_id = ? AND invite_status = \'accepted\'', [$clientId, $stallId]) !== null;
    }

    /** Discussion possible : le client suit l'étal ou a accepté d'être son client */
    public static function linked(int $clientId, int $stallId): bool
    {
        return self::follows($clientId, $stallId) || self::accepted($clientId, $stallId);
    }

    public static function follows(int $clientId, int $stallId): bool
    {
        return Database::value('SELECT 1 FROM stall_follows WHERE user_id = ? AND stall_id = ?', [$clientId, $stallId]) !== null;
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
                // Vocaux et vidéos sont des fichiers à part (les photos restent pour les aperçus)
                if (in_array($r['kind'], ['voice', 'video'], true) && $r['file_path'] !== null) {
                    @unlink(BASE_PATH . '/storage/' . $r['file_path']);
                }
            }
            $ids = array_column($rows, 'id');
            $total += Database::run('DELETE FROM messages WHERE id IN (' . Database::in($ids) . ')', $ids)->rowCount();
        } while (count($rows) === 1000);
        return $total;
    }
}
