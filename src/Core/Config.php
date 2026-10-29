<?php
declare(strict_types=1);

namespace Axi\Core;

final class Config
{
    private static array $values = [];

    public static function load(array $values): void
    {
        self::$values = $values;
    }

    /** Lecture par chemin pointé : Config::get('auth.otp_ttl_seconds') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function isLocal(): bool
    {
        return self::get('env') === 'local';
    }
}
