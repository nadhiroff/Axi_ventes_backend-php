<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Core\Url;
use Axi\Services\Http;

/**
 * FedaPay : le serveur crée la transaction, l'application ouvre payment_url (WebView),
 * puis FedaPay redirige vers /v1/payments/fedapay/callback.
 */
final class FedaPayGateway implements PaymentGateway
{
    public function __construct()
    {
        if ((string) Config::get('payments.fedapay.secret_key') === '') {
            throw NotConfigured::provider('FedaPay', 'payments.fedapay.secret_key');
        }
    }

    public function init(array $payment, string $description): array
    {
        [$status, $body] = Http::json('POST', $this->base() . '/v1/transactions', $this->headers(), [
            'description' => $description,
            'amount' => (int) $payment['amount'],
            'currency' => ['iso' => 'XOF'],
            'callback_url' => Url::to('/v1/payments/fedapay/callback'),
        ]);
        $id = $body['v1/transaction']['id'] ?? null;
        if ($status >= 300 || $id === null) {
            Logger::write('payments', "FedaPay création refusée ($status) : " . json_encode($body));
            throw new HttpException(502, 'PAYMENT_INIT_FAILED', 'Impossible de lancer le paiement, réessayez');
        }

        [$status, $token] = Http::json('POST', $this->base() . "/v1/transactions/$id/token", $this->headers());
        if ($status >= 300 || empty($token['url'])) {
            Logger::write('payments', "FedaPay jeton refusé ($status) : " . json_encode($token));
            throw new HttpException(502, 'PAYMENT_INIT_FAILED', 'Impossible de lancer le paiement, réessayez');
        }
        return [(string) $id, ['payment_url' => $token['url']]];
    }

    public function fetchStatus(array $payment, string $providerRef): array
    {
        [$status, $body] = Http::json('GET', $this->base() . '/v1/transactions/' . rawurlencode($providerRef), $this->headers());
        $transaction = $body['v1/transaction'] ?? null;
        if ($status >= 300 || !is_array($transaction)) {
            return ['status' => 'pending', 'amount' => 0];
        }
        return [
            'status' => match ($transaction['status'] ?? '') {
                'approved', 'transferred' => 'approved',
                'pending' => 'pending',
                default => 'failed',
            },
            'amount' => (int) ($transaction['amount'] ?? 0),
        ];
    }

    private function base(): string
    {
        return Config::get('payments.mode') === 'live' ? 'https://api.fedapay.com' : 'https://sandbox-api.fedapay.com';
    }

    private function headers(): array
    {
        return ['Authorization: Bearer ' . Config::get('payments.fedapay.secret_key')];
    }
}
