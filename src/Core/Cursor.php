<?php
declare(strict_types=1);

namespace Axi\Core;

/** Pagination par curseur opaque (?cursor=) : le client renvoie tel quel le next_cursor reçu. */
final class Cursor
{
    public static function offset(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        return $decoded !== false && preg_match('/^o:(\d{1,7})$/', $decoded, $m) ? (int) $m[1] : 0;
    }

    /** Curseur de la page suivante, ou null si $fetched (lu avec limit + 1) n'en annonce pas d'autre */
    public static function next(int $offset, int $limit, int $fetched): ?string
    {
        return $fetched > $limit ? rtrim(strtr(base64_encode('o:' . ($offset + $limit)), '+/', '-_'), '=') : null;
    }
}
