<?php
declare(strict_types=1);

namespace Axi\Core;

final class Response
{
    public static function ok(mixed $data, int $status = 200, ?array $meta = null): never
    {
        $payload = ['ok' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
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

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    private static function send(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
