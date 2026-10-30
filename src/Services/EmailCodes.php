<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Clock;
use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Logger;

/**
 * Codes à 6 chiffres envoyés par email (vérification de l'adresse, nouveau mot de passe).
 * Stockés en HMAC-SHA256 (APP_KEY), valables 15 minutes, 5 essais ; seul le dernier code compte.
 */
final class EmailCodes
{
    /**
     * Crée et envoie un code. Renvoie le nombre de secondes à attendre si la limite d'envoi est atteinte
     * (1 par minute, 5 par heure), sinon 0. $user null : adresse inconnue, une ligne est quand même
     * enregistrée (sans envoi) pour que les limites ne révèlent pas quelles adresses ont un compte.
     */
    public static function issue(string $email, string $purpose, ?array $user): int
    {
        $wait = self::retryAfter($email, $purpose);
        if ($wait > 0) {
            return $wait;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int) Config::get('auth.code_ttl_seconds');
        Database::run(
            'INSERT INTO email_codes (email, purpose, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$email, $purpose, self::hash($code), Clock::now($ttl), Clock::now()],
        );
        if ($user === null) {
            return 0;
        }

        if (Config::isLocal()) {
            // Pour tester sans boîte mail (tests/smoke.sh lit ce fichier)
            Logger::write('codes', "$email $purpose $code");
        }
        [$subject, $html, $text] = EmailTemplates::code($purpose, (string) $user['first_name'], $code, intdiv($ttl, 60));
        Mailer::send($email, $subject, $html, $text);
        return 0;
    }

    /** Vérifie le code ; le consomme s'il est bon, sinon lève l'erreur prévue par le contrat. */
    public static function consume(string $email, string $purpose, string $code): void
    {
        $row = Database::one(
            'SELECT * FROM email_codes WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
            [$email, $purpose],
        );
        $max = (int) Config::get('auth.code_max_attempts');

        if ($row === null || $row['used_at'] !== null) {
            throw new HttpException(422, 'CODE_INVALID', 'Code incorrect. Demandez un nouveau code.', ['remaining_attempts' => 0]);
        }
        if ((int) $row['attempts'] >= $max) {
            throw new HttpException(429, 'TOO_MANY_ATTEMPTS', 'Trop d\'essais avec ce code. Demandez un nouveau code.');
        }
        if ($row['expires_at'] <= Clock::now()) {
            throw new HttpException(422, 'CODE_EXPIRED', 'Ce code a expiré. Demandez un nouveau code.');
        }

        Database::run('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        if (!hash_equals($row['code_hash'], self::hash($code))) {
            $remaining = $max - (int) $row['attempts'] - 1;
            if ($remaining <= 0) {
                throw new HttpException(429, 'TOO_MANY_ATTEMPTS', 'Trop d\'essais avec ce code. Demandez un nouveau code.');
            }
            $message = $remaining === 1 ? 'Code incorrect. Il vous reste 1 essai.' : "Code incorrect. Il vous reste $remaining essais.";
            throw new HttpException(422, 'CODE_INVALID', $message, ['remaining_attempts' => $remaining]);
        }
        Database::run('UPDATE email_codes SET used_at = ? WHERE id = ?', [Clock::now(), $row['id']]);
    }

    private static function retryAfter(string $email, string $purpose): int
    {
        $last = Database::one(
            'SELECT MAX(created_at) AS last_at, SUM(created_at > ?) AS last_hour
             FROM email_codes WHERE email = ? AND purpose = ? AND created_at > ?',
            [Clock::now(-3600), $email, $purpose, Clock::now(-3600)],
        );
        if ($last === null || $last['last_at'] === null) {
            return 0;
        }
        $sinceLast = time() - strtotime($last['last_at'] . ' UTC');
        $resend = (int) Config::get('auth.code_resend_seconds');
        if ($sinceLast < $resend) {
            return $resend - $sinceLast;
        }
        if ((int) $last['last_hour'] >= (int) Config::get('auth.code_max_per_hour')) {
            $oldest = (string) Database::value(
                'SELECT created_at FROM email_codes WHERE email = ? AND purpose = ? AND created_at > ? ORDER BY id ASC LIMIT 1',
                [$email, $purpose, Clock::now(-3600)],
            );
            return max(1, 3600 - (time() - strtotime($oldest . ' UTC')));
        }
        return 0;
    }

    private static function hash(string $code): string
    {
        $key = (string) Config::get('app_key');
        if (strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY manquante ou trop courte dans .env');
        }
        return hash_hmac('sha256', $code, $key);
    }
}
