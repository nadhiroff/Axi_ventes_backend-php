<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Url;

/**
 * Liens temporaires signés (HMAC, APP_KEY) : écoute d'un message vocal, export CSV, page de paiement.
 * Le lien porte ses paramètres, une date d'expiration et une signature ; rien n'est stocké.
 */
final class SignedUrl
{
    public static function make(string $path, array $params, int $ttlSeconds): string
    {
        $params['exp'] = time() + $ttlSeconds;
        ksort($params);
        $params['sig'] = self::sign($path, $params);
        return Url::to($path) . '?' . http_build_query($params);
    }

    /** @return array les paramètres signés (sans exp ni sig) */
    public static function verify(string $path, array $query): array
    {
        $sig = (string) ($query['sig'] ?? '');
        unset($query['sig']);
        ksort($query);
        if ($sig === '' || !hash_equals(self::sign($path, $query), $sig) || (int) ($query['exp'] ?? 0) < time()) {
            throw new HttpException(403, 'LINK_EXPIRED', 'Ce lien a expiré. Recommencez depuis l\'application.');
        }
        unset($query['exp']);
        return $query;
    }

    private static function sign(string $path, array $params): string
    {
        return hash_hmac('sha256', $path . '?' . http_build_query($params), (string) Config::get('app_key'));
    }
}
