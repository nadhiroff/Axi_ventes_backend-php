<?php
declare(strict_types=1);

namespace Axi\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Dates au format DATETIME MySQL, toujours en UTC. Le « jour » du commerçant suit l'heure du Bénin. */
final class Clock
{
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    /** DATETIME MySQL (UTC) vers ISO 8601 pour le JSON */
    public static function iso(?string $datetime): ?string
    {
        return $datetime === null ? null : str_replace(' ', 'T', $datetime) . 'Z';
    }

    /** Date du jour à l'heure locale (Africa/Porto-Novo par défaut) */
    public static function localDate(): string
    {
        return (new DateTimeImmutable('now', self::zone()))->format('Y-m-d');
    }

    /** Minuit local converti en UTC, pour filtrer « aujourd'hui » */
    public static function localDayStart(): string
    {
        return (new DateTimeImmutable('today', self::zone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Date locale (Y-m-d) à 00:00 locale, en UTC */
    public static function localDateStart(string $date): string
    {
        return (new DateTimeImmutable($date . ' 00:00:00', self::zone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function addDays(string $datetime, int $days): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($datetime . ' UTC') + $days * 86400);
    }

    public static function daysUntil(string $datetime): int
    {
        return (int) max(0, ceil((strtotime($datetime . ' UTC') - time()) / 86400));
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone((string) Config::get('timezone', 'Africa/Porto-Novo'));
    }
}
