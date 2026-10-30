<?php
declare(strict_types=1);

namespace Axi\Core;

/** Identifiants publics préfixés du contrat (stl_214, prd_88…) ; jamais d'entier brut dans le JSON. */
final class Ids
{
    public static function encode(string $prefix, int|string|null $id): ?string
    {
        return $id === null ? null : $prefix . '_' . $id;
    }

    /** « stl_214 » → 214 ; sinon 404 avec le code d'erreur fourni */
    public static function decode(string $prefix, mixed $value, string $errorCode = 'NOT_FOUND', string $message = 'Ressource introuvable.', int $status = 404): int
    {
        if (is_string($value) && preg_match('/^' . preg_quote($prefix, '/') . '_([1-9]\d{0,9})$/', $value, $m)) {
            return (int) $m[1];
        }
        throw new HttpException($status, $errorCode, $message);
    }

    public static function tryDecode(string $prefix, mixed $value): ?int
    {
        return is_string($value) && preg_match('/^' . preg_quote($prefix, '/') . '_([1-9]\d{0,9})$/', $value, $m) ? (int) $m[1] : null;
    }
}
