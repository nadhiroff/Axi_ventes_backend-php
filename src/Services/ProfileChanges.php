<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\Logger;

/**
 * Historique des modifications de profil et d'étal (table profile_changes), lu par les agents Axi :
 * un vrai nom remplacé par un pseudonyme, un étal renommé, un numéro changé se voient tout de suite.
 */
final class ProfileChanges
{
    /** Libellés montrés aux agents */
    public const LABELS = [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'phone' => 'Téléphone',
        'bio' => 'Présentation',
        'avatar' => 'Photo de profil',
        'stall_name' => 'Nom de l\'étal',
        'tagline' => 'Ce que vend l\'étal',
        'stall_phone' => 'Téléphone de l\'étal',
        'whatsapp' => 'WhatsApp de l\'étal',
        'site_id' => 'Marché',
        'site_other' => 'Marché (autre)',
        'aisle_id' => 'Allée',
        'aisle_other' => 'Allée (autre)',
        'row_label' => 'Rangée',
        'stand' => 'Stand',
        'merchant_type' => 'Type de vente',
        'stall_photo' => 'Photo de l\'étal',
    ];

    /** Champs d'identité : une modification est signalée en évidence aux agents */
    public const IDENTITY = ['first_name', 'last_name', 'stall_name'];

    /**
     * Enregistre chaque champ dont la valeur a changé. $before et $after : valeurs indexées par nom de champ
     * (seuls les champs présents dans $after sont comparés). Ne bloque jamais la modification elle-même.
     */
    public static function record(int $userId, ?int $stallId, array $before, array $after, string $source = 'app', ?int $actorId = null): void
    {
        try {
            foreach ($after as $field => $new) {
                $old = $before[$field] ?? null;
                $norm = static fn (mixed $v): ?string => $v === null || $v === '' ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
                if ($norm($old) === $norm($new)) {
                    continue;
                }
                Database::run(
                    'INSERT INTO profile_changes (user_id, stall_id, field, old_value, new_value, source, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$userId, $stallId, mb_substr((string) $field, 0, 40), self::cut($norm($old)), self::cut($norm($new)), $source, $actorId, Clock::now()],
                );
            }
        } catch (\Throwable $e) {
            Logger::write('error', 'Historique des modifications non enregistré : ' . $e->getMessage());
        }
    }

    /** Colonnes de stalls suivies → nom du champ dans l'historique */
    private const STALL_COLUMNS = [
        'name' => 'stall_name', 'tagline' => 'tagline', 'phone' => 'stall_phone', 'whatsapp' => 'whatsapp',
        'site_id' => 'site_id', 'site_other' => 'site_other', 'aisle_id' => 'aisle_id', 'aisle_other' => 'aisle_other',
        'row_label' => 'row_label', 'stand' => 'stand', 'merchant_type' => 'merchant_type', 'photo_path' => 'stall_photo',
    ];

    /** Modification d'un étal : $stall = ligne avant, $columns = colonnes écrites */
    public static function recordStall(array $stall, array $columns, string $source = 'app', ?int $actorId = null): void
    {
        $before = [];
        $after = [];
        foreach (self::STALL_COLUMNS as $column => $field) {
            if (array_key_exists($column, $columns)) {
                $before[$field] = $stall[$column] ?? null;
                $after[$field] = $columns[$column];
            }
        }
        self::record((int) $stall['user_id'], (int) $stall['id'], $before, $after, $source, $actorId);
    }

    /** Dernières modifications d'un utilisateur (profil et étal) */
    public static function forUser(int $userId, int $limit = 50): array
    {
        return Database::all(
            'SELECT c.*, a.name AS actor_name FROM profile_changes c LEFT JOIN users a ON a.id = c.actor_id WHERE c.user_id = ? ORDER BY c.id DESC LIMIT ' . max(1, $limit),
            [$userId],
        );
    }

    /** Nombre de modifications du nom (personne ou étal) faites par l'utilisateur lui-même */
    public static function identityChanges(int $userId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM profile_changes WHERE user_id = ? AND source = 'app' AND field IN ('first_name', 'last_name', 'stall_name')",
            [$userId],
        );
    }

    private static function cut(?string $v): ?string
    {
        return $v === null ? null : mb_substr($v, 0, 600);
    }
}
