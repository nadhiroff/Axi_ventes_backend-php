<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\Database;

/**
 * Blocages entre utilisateurs (table user_blocks). Un blocage cache les deux personnes l'une à l'autre, dans les
 * DEUX sens : celui qui bloque ne voit plus rien de l'autre, et l'autre ne voit plus rien de lui (ni profil,
 * ni étal, ni produits) et ne peut plus lui écrire ni lui commander.
 *
 * La personne connectée (« viewer ») est retenue pour la requête en cours : les listes d'étals et de produits
 * (Stalls::query) écartent alors d'elles-mêmes les comptes cachés, sans que chaque écran ait à y penser.
 */
final class Blocks
{
    private static ?int $viewer = null;
    /** @var array<int, list<int>> */
    private static array $hidden = [];

    public static function setViewer(?int $userId): void
    {
        self::$viewer = $userId;
    }

    public static function viewer(): ?int
    {
        return self::$viewer;
    }

    /** Comptes cachés pour $userId : ceux qu'il a bloqués et ceux qui l'ont bloqué */
    public static function hiddenFor(int $userId): array
    {
        return self::$hidden[$userId] ??= array_map('intval', array_column(Database::all(
            'SELECT blocked_id AS id FROM user_blocks WHERE blocker_id = ? UNION SELECT blocker_id FROM user_blocks WHERE blocked_id = ?',
            [$userId, $userId],
        ), 'id'));
    }

    /** Blocage entre les deux, dans un sens ou dans l'autre */
    public static function between(int $a, int $b): bool
    {
        return $a !== $b && in_array($b, self::hiddenFor($a), true);
    }

    public static function blockedByMe(int $me, int $other): bool
    {
        return Database::value('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$me, $other]) !== null;
    }

    /**
     * Condition SQL qui écarte les comptes cachés pour la personne connectée : [« colonne NOT IN (…) », paramètres].
     * Sans personne connectée (visiteur, worker, espace agent) ou sans blocage : ['1', []].
     * @return array{0: string, 1: list<int>}
     */
    public static function excludeSql(string $column, ?int $viewer = null): array
    {
        $viewer ??= self::$viewer;
        $hidden = $viewer === null ? [] : self::hiddenFor($viewer);
        return $hidden === [] ? ['1', []] : [$column . ' NOT IN (' . Database::in($hidden) . ')', $hidden];
    }

    public static function block(int $me, int $other): void
    {
        Database::run('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$me, $other]);
        // Plus aucun lien : chacun cesse de suivre l'étal de l'autre
        Database::run(
            'DELETE f FROM stall_follows f JOIN stalls s ON s.id = f.stall_id WHERE (f.user_id = ? AND s.user_id = ?) OR (f.user_id = ? AND s.user_id = ?)',
            [$me, $other, $other, $me],
        );
        self::forget();
    }

    public static function unblock(int $me, int $other): void
    {
        Database::run('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$me, $other]);
        self::forget();
    }

    private static function forget(): void
    {
        self::$hidden = [];
    }
}
