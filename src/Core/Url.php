<?php
declare(strict_types=1);

namespace Axi\Core;

/** Construit les URL absolues renvoyées à l'application (photos, vocaux, retours de paiement). */
final class Url
{
    private static string $base = '';

    public static function setBase(string $base): void
    {
        self::$base = rtrim($base, '/');
    }

    /** Chemin relatif stocké en base (uploads/images/...) vers URL complète */
    public static function asset(?string $path): ?string
    {
        return $path === null || $path === '' ? null : self::$base . '/' . ltrim($path, '/');
    }

    public static function to(string $path): string
    {
        return self::$base . '/' . ltrim($path, '/');
    }
}
