<?php
declare(strict_types=1);

namespace Axi\Core;

final class Auth
{
    /** Vérifie le jeton Bearer et le rôle ; remplit $request->user. */
    public static function authenticate(Request $request, array $roles): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Connectez-vous pour continuer.');
        }
        $row = self::resolve($token);
        if ($row === null) {
            // Session coupée par un blocage : la personne doit savoir pourquoi, pas lire « session expirée »
            $reason = Database::one('SELECT u.suspended_reason FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND u.status = \'suspended\'', [hash('sha256', $token)]);
            if ($reason !== null) {
                throw self::suspended($reason['suspended_reason']);
            }
            throw new HttpException(401, 'UNAUTHENTICATED', 'Votre session a expiré. Reconnectez-vous.');
        }
        if ($row['status'] !== 'active') {
            throw self::suspended($row['suspended_reason'] ?? null);
        }
        if ($roles && !in_array($row['role'], $roles, true)) {
            throw new HttpException(403, 'FORBIDDEN', 'Votre profil ne donne pas accès à cette page.');
        }
        self::login($request, $row);
    }

    /** Même texte partout : à la connexion, ou quand le blocage arrive pendant que l'application est ouverte */
    public static function suspendedMessage(?string $reason): string
    {
        $contact = self::supportEmail();
        return 'Votre accès à Axi a été bloqué par l\'équipe Axi.' . ($reason ? ' Motif : ' . $reason : '')
            . ($contact !== '' ? " Pour en savoir plus, écrivez à $contact." : '');
    }

    /** Adresse à contacter (Réglages), sinon celle qui envoie les emails d'Axi */
    public static function supportEmail(): string
    {
        return trim(\Axi\Services\Settings::text('support_email')) ?: (string) Config::get('mail.from_address');
    }

    /** Refus d'un compte bloqué : le message, et l'adresse pour le bouton « Contacter » de l'application */
    public static function suspended(?string $reason): HttpException
    {
        return new HttpException(403, 'ACCOUNT_SUSPENDED', self::suspendedMessage($reason), ['support_email' => self::supportEmail()]);
    }

    /** Routes publiques : reconnaît l'utilisateur s'il envoie un jeton valide, sinon reste anonyme. */
    public static function attempt(Request $request): void
    {
        $token = $request->bearerToken();
        $row = $token === null ? null : self::resolve($token);
        if ($row !== null && $row['status'] === 'active') {
            self::login($request, $row);
        }
    }

    /** Crée un jeton de session ; le jeton en clair n'est renvoyé qu'une fois, seul son sha256 est stocké. */
    public static function issueToken(int $userId, ?string $device, ?string $ip = null): string
    {
        $token = bin2hex(random_bytes(32));
        $now = Clock::now();
        Database::run(
            'INSERT INTO auth_tokens (user_id, token_hash, device, ip, expires_at, last_used_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, hash('sha256', $token), $device === null ? null : mb_substr($device, 0, 120), $ip, Clock::now(self::ttl()), $now, $now],
        );
        return $token;
    }

    public static function revoke(int $tokenId): void
    {
        Database::run('UPDATE auth_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::now(), $tokenId]);
    }

    /** Révoque toutes les sessions d'un utilisateur, sauf éventuellement celle en cours */
    public static function revokeAll(int $userId, ?int $exceptTokenId = null): void
    {
        Database::run(
            'UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [Clock::now(), $userId, $exceptTokenId ?? 0],
        );
    }

    private static function resolve(string $token): ?array
    {
        // Rejette les jetons mal formés sans toucher à la base
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::one(
            'SELECT u.*, t.id AS token_id FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > ?',
            [hash('sha256', $token), Clock::now()],
        );
    }

    private static function login(Request $request, array $row): void
    {
        // Durée glissante : prolongée à chaque utilisation
        Database::run('UPDATE auth_tokens SET last_used_at = ?, expires_at = ? WHERE id = ?', [Clock::now(), Clock::now(self::ttl()), $row['token_id']]);
        $request->tokenId = (int) $row['token_id'];
        $request->user = $row;
    }

    private static function ttl(): int
    {
        return (int) Config::get('auth.token_ttl_days', 90) * 86400;
    }
}
