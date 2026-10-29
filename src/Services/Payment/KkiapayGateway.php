<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Config;
use Axi\Services\HttpClient;
use Axi\Services\SignedUrl;

/**
 * KkiaPay (https://docs.kkiapay.me) : le paiement se fait dans le widget JavaScript, ouvert sur une page
 * hébergée par Axi (/v1/payments/kkiapay) avec partnerId = référence Axi. Le statut est ensuite vérifié
 * serveur à serveur (POST /api/v1/transactions/status) : montant et partnerId doivent correspondre.
 */
final class KkiapayGateway implements Gateway
{
    private array $config;

    public function __construct()
    {
        $this->config = Config::get('payments.kkiapay');
        foreach (['public_key', 'private_key', 'secret'] as $key) {
            if (($this->config[$key] ?? '') === '') {
                throw new GatewayException("KkiaPay : $key manquante dans config/config.local.php");
            }
        }
    }

    public function name(): string
    {
        return 'kkiapay';
    }

    public function isSandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? true);
    }

    public function publicKey(): string
    {
        return (string) $this->config['public_key'];
    }

    public function create(array $payment, array $customer, string $returnUrl): array
    {
        // Rien à créer côté KkiaPay : la page signée ouvre le widget pour CE paiement (montant lu en base)
        return [
            'provider_ref' => $payment['provider_ref'],
            'payment_url' => SignedUrl::make('/v1/payments/kkiapay', ['ref' => $payment['reference']], 3 * 3600),
        ];
    }

    public function status(array $payment): array
    {
        if ($payment['provider_ref'] === null) {
            return ['state' => 'pending', 'amount' => null, 'provider_ref' => null, 'raw' => 'no_transaction'];
        }
        $r = $this->call('/api/v1/transactions/status', ['transactionId' => $payment['provider_ref']]);
        $status = (string) ($r['body']['status'] ?? '');
        if ($status === 'TRANSACTION_NOT_FOUND') {
            return ['state' => 'pending', 'amount' => null, 'provider_ref' => null, 'raw' => $status];
        }
        $partner = (string) ($r['body']['partnerId'] ?? $r['body']['state'] ?? '');
        if ($partner !== '' && $partner !== $payment['reference']) {
            throw new GatewayException('KkiaPay : la transaction appartient à une autre référence');
        }
        $state = match ($status) {
            'SUCCESS' => 'paid',
            'FAILED', 'INSUFFICIENT_FUND', 'TRANSACTION_FAILED' => 'failed',
            'REVERTED', 'REFUNDED' => 'refunded',
            'CANCELLED', 'CANCELED' => 'cancelled',
            default => 'pending',
        };
        return ['state' => $state, 'amount' => isset($r['body']['amount']) ? (int) $r['body']['amount'] : null, 'provider_ref' => (string) $payment['provider_ref'], 'raw' => $status];
    }

    public function refund(array $payment, int $amount): array
    {
        if ($amount !== (int) $payment['amount'] - (int) $payment['refunded_amount']) {
            return ['status' => 'pending', 'provider_ref' => null, 'message' => 'KkiaPay ne rembourse que la totalité : remboursement partiel à faire depuis le tableau de bord KkiaPay.'];
        }
        $r = $this->call('/api/v1/transactions/revert', ['transactionId' => $payment['provider_ref']]);
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        return ['status' => $ok ? 'succeeded' : 'failed', 'provider_ref' => (string) $payment['provider_ref'], 'message' => $ok ? 'Remboursé par KkiaPay.' : 'KkiaPay a refusé le remboursement : ' . mb_substr($r['raw'], 0, 160)];
    }

    public function payout(int $amount, string $phone, string $name, string $reference): array
    {
        if ($this->isSandbox()) {
            return ['status' => 'simulated', 'provider_ref' => 'sandbox_' . $reference, 'message' => 'Versement simulé (sandbox).'];
        }
        // Les reversements KkiaPay se configurent dans son tableau de bord
        return ['status' => 'failed', 'provider_ref' => null, 'message' => 'Versement KkiaPay à effectuer depuis le tableau de bord KkiaPay.'];
    }

    /** En-tête x-kkiapay-secret = clé secrète du compte */
    public function parseWebhook(array $headers, string $rawBody): ?array
    {
        $given = (string) ($headers['x-kkiapay-secret'] ?? '');
        if ($given === '' || !hash_equals((string) $this->config['secret'], $given)) {
            return null;
        }
        $event = json_decode($rawBody, true) ?: [];
        $tx = isset($event['transactionId']) ? (string) $event['transactionId'] : null;
        return [
            'key' => ($event['event'] ?? 'event') . ':' . ($tx ?? md5($rawBody)),
            'provider_ref' => $tx,
            'reference' => $event['partnerId'] ?? ($event['stateData'] ?? null),
        ];
    }

    private function call(string $path, array $body): array
    {
        $base = $this->isSandbox() ? 'https://api-sandbox.kkiapay.me' : 'https://api.kkiapay.me';
        $r = HttpClient::json('POST', $base . $path, [
            'x-api-key' => $this->config['public_key'],
            'x-private-key' => $this->config['private_key'],
            'x-secret-key' => $this->config['secret'],
        ], $body);
        if ($r['status'] === 0) {
            throw new GatewayException('KkiaPay injoignable : ' . $r['raw']);
        }
        if ($r['status'] === 401) {
            throw new GatewayException('KkiaPay a refusé les clés : vérifiez une possible erreur de recopie dans config/config.local.php');
        }
        return $r;
    }
}
