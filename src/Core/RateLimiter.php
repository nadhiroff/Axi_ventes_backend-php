<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Limite globale de requêtes, par fenêtre d'une minute : une par adresse IP (large, car un opérateur mobile ou
 * le Wi-Fi d'un marché partage une même adresse entre beaucoup de gens) et une par session (jeton).
 * Protège la base contre une boucle de l'application, un robot ou un essai massif de jetons.
 * Compteurs en mémoire (APCu) si l'extension est chargée, sinon en base (une requête par compteur).
 * Si la base ne répond pas, la requête passe : la limite ne doit jamais rendre l'API indisponible.
 */
final class RateLimiter
{
    /** Ni les passerelles de paiement ni la surveillance ne sont jamais limitées */
    private const EXEMPT = ['#^/v1/webhooks/#', '#^/v1/payments/#', '#^/v1/health$#', '#^/v1/internal/#'];

    public static function check(Request $request): void
    {
        $config = (array) Config::get('rate_limit', []);
        if (!($config['enabled'] ?? true)) {
            return;
        }
        foreach (self::EXEMPT as $pattern) {
            if (preg_match($pattern, $request->path)) {
                return;
            }
        }
        $window = intdiv(time(), 60);
        $limits = ['ip:' . $request->ip() => (int) ($config['per_ip_per_minute'] ?? 600)];
        $token = $request->bearerToken();
        if ($token !== null) {
            $limits['tok:' . hash('sha256', $token)] = (int) ($config['per_session_per_minute'] ?? 300);
        }
        foreach ($limits as $key => $limit) {
            if ($limit > 0 && self::hit(sha1($key), $window) > $limit) {
                $retry = 60 - time() % 60;
                Logger::write('security', 'LIMITE ' . strtok($key, ':') . ' dépassée ' . $request->ip() . ' ' . $request->method . ' ' . $request->path);
                throw new HttpException(429, 'TOO_MANY_REQUESTS', 'Trop de demandes en peu de temps. Patientez quelques secondes puis réessayez.', ['retry_after' => $retry], ['Retry-After' => (string) $retry]);
            }
        }
    }

    /** Nombre de requêtes dans la fenêtre, celle-ci comprise */
    private static function hit(string $bucket, int $window): int
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $key = "axi_rl_{$bucket}_$window";
            apcu_add($key, 0, 120);
            return (int) apcu_inc($key);
        }
        try {
            // LAST_INSERT_ID(expr) : le compteur est relu dans la même requête, sans second aller-retour
            Database::run(
                'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
                [$bucket, $window],
            );
            return (int) Database::pdo()->lastInsertId();
        } catch (\PDOException $e) {
            Logger::write('security', 'Limite de requêtes indisponible : ' . $e->getMessage());
            return 0;
        }
    }

    /** Ménage (worker) : fenêtres de plus de 5 minutes */
    public static function purge(): int
    {
        return Database::run('DELETE FROM rate_limits WHERE window_start < ?', [intdiv(time(), 60) - 5])->rowCount();
    }
}
