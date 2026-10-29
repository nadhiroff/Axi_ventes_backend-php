<?php
declare(strict_types=1);

namespace Axi\Core;

/** Dates au format DATETIME MySQL, toujours en UTC. */
final class Clock
{
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    /** DATETIME MySQL (UTC) vers ISO 8601 pour le JSON */
    public static function iso(string $datetime): string
    {
        return str_replace(' ', 'T', $datetime) . 'Z';
    }
}
