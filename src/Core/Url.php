<?php
declare(strict_types=1);

namespace Axi\Core;

/** Construit les URL absolues renvoyées à l'application (photos, vocaux, retours de paiement). */
final class Url
{
    private static string $routeBase = '';
    private static string $assetBase = '';

    /** $routeBase : liens vers les routes (peut contenir index.php) ; $assetBase : dossier public (photos) */
    public static function setBases(string $routeBase, string $assetBase): void
    {
        self::$routeBase = rtrim($routeBase, '/');
        self::$assetBase = rtrim($assetBase, '/');
    }

    /** Chemin relatif stocké en base (uploads/p/...) vers URL complète du fichier */
    public static function asset(?string $path): ?string
    {
        return $path === null || $path === '' ? null : self::$assetBase . '/' . ltrim($path, '/');
    }

    /** Lien vers une route de l'API ou de l'espace web */
    public static function to(string $path): string
    {
        return self::$routeBase . '/' . ltrim($path, '/');
    }
}
