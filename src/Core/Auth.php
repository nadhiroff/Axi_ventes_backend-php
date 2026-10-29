<?php
declare(strict_types=1);

namespace Axi\Core;

use Axi\Models\AuthToken;

final class Auth
{
    /** Vérifie le jeton Bearer et le rôle ; remplit $request->user. */
    public static function authenticate(Request $request, array $roles): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Connexion requise');
        }
        // Rejette les jetons mal formés sans toucher à la base
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new HttpException(401, 'TOKEN_INVALID', 'Session invalide ou expirée');
        }

        $row = AuthToken::findActive(self::hashToken($token), Clock::now());
        if ($row === null) {
            throw new HttpException(401, 'TOKEN_INVALID', 'Session invalide ou expirée');
        }
        if ($row['status'] !== 'active') {
            throw new HttpException(403, 'ACCOUNT_SUSPENDED', 'Compte suspendu');
        }
        if ($roles && !in_array($row['role'], $roles, true)) {
            throw new HttpException(403, 'FORBIDDEN', 'Accès non autorisé pour ce profil');
        }

        AuthToken::touch((int) $row['token_id'], self::tokenTtlSeconds());

        $request->tokenId = (int) $row['token_id'];
        $request->user = $row;
    }

    /** Crée un jeton de session et renvoie [jeton en clair, date d'expiration]. */
    public static function issueToken(int $userId, ?string $device): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = Clock::now(self::tokenTtlSeconds());
        AuthToken::create($userId, self::hashToken($token), $device, $expiresAt);
        return [$token, $expiresAt];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function tokenTtlSeconds(): int
    {
        return (int) Config::get('auth.token_ttl_days') * 86400;
    }
}
