<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Enveloppe du contrat : {"ok":true,"data":…,"meta":{server_time,max_age_s}} ou {"ok":false,"error":{code,message}}.
 * Les GET portent un ETag (md5 de data) et répondent 304 sans corps si If-None-Match correspond.
 */
final class Response
{
    public static function ok(mixed $data, int $status = 200, int $maxAge = 0): never
    {
        $payload = [
            'ok' => true,
            'data' => $data,
            'meta' => ['server_time' => gmdate('Y-m-d\TH:i:s\Z'), 'max_age_s' => $maxAge],
        ];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $status === 200) {
            // L'ETag ignore server_time, sinon il changerait à chaque appel
            $etag = '"' . md5(self::encode($data)) . '"';
            header('ETag: ' . $etag);
            // no-cache : le téléphone revalide toujours (304 sans corps si rien n'a changé). Avec max-age,
            // le cache HTTP d'iOS resservait une ancienne réponse (ex. « Suivre » sans effet visible).
            header('Cache-Control: private, no-cache');
            header('Vary: Authorization, Accept-Encoding');
            if (self::matches($_SERVER['HTTP_IF_NONE_MATCH'] ?? '', $etag)) {
                http_response_code(304);
                exit;
            }
            self::send($payload, $status, false);
        }
        self::send($payload, $status);
    }

    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): never
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        self::send(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $details], $status);
    }

    /** If-None-Match peut contenir plusieurs valeurs, un préfixe W/ ou le suffixe -gzip ajouté par Apache */
    private static function matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = (string) preg_replace(['#^W/#', '#-gzip"$#'], ['', '"'], trim($candidate));
            if ($candidate === $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function send(array $payload, int $status, bool $noStore = true): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if ($noStore) {
            header('Cache-Control: no-store');
        }
        echo self::encode($payload);
        exit;
    }
}
