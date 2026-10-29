<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\HttpException;
use Axi\Core\Logger;

/** Client HTTP minimal (cURL) pour les API de paiement */
final class Http
{
    /** Renvoie [code HTTP, corps JSON décodé] */
    public static function json(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('L\'extension PHP cURL est requise pour les paiements');
        }
        $ch = curl_init($url);
        $headers[] = 'Accept: application/json';
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            Logger::write('payments', "$method $url : " . curl_error($ch));
            throw new HttpException(502, 'PAYMENT_PROVIDER_UNAVAILABLE', 'Service de paiement injoignable, réessayez');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $decoded = json_decode((string) $raw, true);
        return [$status, is_array($decoded) ? $decoded : []];
    }
}
