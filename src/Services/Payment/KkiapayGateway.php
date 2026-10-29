<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Config;
use Axi\Services\Http;

/**
 * Kkiapay : l'application ouvre le widget (SDK React Native) avec les données renvoyées par init,
 * puis transmet le transactionId à POST /v1/payments/{id}/verify.
 */
final class KkiapayGateway implements PaymentGateway
{
    public function __construct()
    {
        foreach (['public_key', 'private_key', 'secret'] as $key) {
            if ((string) Config::get('payments.kkiapay.' . $key) === '') {
                throw NotConfigured::provider('Kkiapay', 'payments.kkiapay.' . $key);
            }
        }
    }

    public function init(array $payment, string $description): array
    {
        return [null, ['kkiapay' => [
            'public_key' => (string) Config::get('payments.kkiapay.public_key'),
            'amount' => (int) $payment['amount'],
            'reason' => $description,
            'data' => (string) $payment['id'],
            'sandbox' => Config::get('payments.mode') !== 'live',
        ]]];
    }

    public function fetchStatus(array $payment, string $providerRef): array
    {
        $base = Config::get('payments.mode') === 'live' ? 'https://api.kkiapay.me' : 'https://api-sandbox.kkiapay.me';
        [$status, $body] = Http::json('POST', $base . '/api/v1/transactions/status', [
            'x-api-key: ' . Config::get('payments.kkiapay.public_key'),
            'x-private-key: ' . Config::get('payments.kkiapay.private_key'),
            'x-secret-key: ' . Config::get('payments.kkiapay.secret'),
        ], ['transactionId' => $providerRef]);

        if ($status >= 300) {
            return ['status' => $status === 404 ? 'failed' : 'pending', 'amount' => 0];
        }
        return [
            'status' => match (strtoupper((string) ($body['status'] ?? ''))) {
                'SUCCESS' => 'approved',
                'PENDING' => 'pending',
                default => 'failed',
            },
            'amount' => (int) ($body['amount'] ?? 0),
        ];
    }
}
