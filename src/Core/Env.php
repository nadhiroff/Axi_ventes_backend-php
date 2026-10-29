<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Lecture du fichier .env (CLE=valeur, une par ligne, # pour les commentaires).
 * Les variables déjà définies par le serveur (SetEnv, docker…) sont prioritaires.
 */
final class Env
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Fichier .env introuvable : copiez .env.example en .env');
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
                $value = $m[2];
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $server = getenv($key);
        if ($server !== false && $server !== '') {
            return $server;
        }
        $value = self::$values[$key] ?? '';
        return $value === '' ? $default : $value;
    }
}
