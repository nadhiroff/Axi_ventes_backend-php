<?php
declare(strict_types=1);

namespace Axi\Services;

/** Appels JSON serveur à serveur (passerelles de paiement) avec délai maximal. */
final class HttpClient
{
    /** @return array{status: int, body: array, raw: string} ; status 0 = réseau injoignable */
    public static function json(string $method, string $url, array $headers = [], ?array $body = null, int $timeout = 20): array
    {
        $ch = curl_init($url);
        $h = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'body' => is_string($raw) ? (json_decode($raw, true) ?: []) : [], 'raw' => is_string($raw) ? $raw : (string) curl_error($ch)];
    }
}
