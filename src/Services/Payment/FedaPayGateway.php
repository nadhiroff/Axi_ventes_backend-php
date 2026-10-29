<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Config;
use Axi\Services\HttpClient;

/**
 * FedaPay (https://docs.fedapay.com) : transaction créée côté serveur, page de paiement hébergée par FedaPay.
 * merchant_reference = référence Axi : FedaPay refuse un doublon (« n'est pas disponible »),
 * on retrouve alors la transaction existante par GET /transactions/merchant/{référence}.
 */
final class FedaPayGateway implements Gateway
{
    private array $config;

    public function __construct()
    {
        $this->config = Config::get('payments.fedapay');
        if (($this->config['secret_key'] ?? '') === '') {
            throw new GatewayException('FedaPay : secret_key manquante dans config/config.local.php');
        }
    }

    public function name(): string
    {
        return 'fedapay';
    }

    public function isSandbox(): bool
    {
        return ($this->config['env'] ?? 'sandbox') !== 'live';
    }

    public function create(array $payment, array $customer, string $returnUrl): array
    {
        $r = $this->call('POST', '/transactions', [
            'description' => $payment['purpose'] === 'order' ? 'Commande Axi ' . $payment['reference'] : 'Abonnement Axi ' . $payment['reference'],
            'amount' => (int) $payment['amount'],
            'currency' => ['iso' => 'XOF'],
            'callback_url' => $returnUrl,
            'merchant_reference' => $payment['reference'],
            'custom_metadata' => ['axi_reference' => $payment['reference']],
            'customer' => ['firstname' => $customer['first_name'], 'lastname' => $customer['last_name'], 'email' => $customer['email']],
        ]);
        $tx = $r['body']['v1/transaction'] ?? null;
        if ($tx === null && isset($r['body']['errors']['merchant_reference'])) {
            // Transaction déjà créée pour cette référence (nouvel essai après une coupure) : on la reprend
            $tx = $this->call('GET', '/transactions/merchant/' . rawurlencode($payment['reference']))['body']['v1/transaction'] ?? null;
        }
        if (!is_array($tx) || !isset($tx['id'])) {
            throw new GatewayException('FedaPay : création refusée (' . $r['status'] . ') ' . mb_substr($r['raw'], 0, 300));
        }
        if ((int) $tx['amount'] !== (int) $payment['amount']) {
            throw new GatewayException('FedaPay : montant de la transaction existante différent');
        }
        $url = $tx['payment_url'] ?? null;
        if (!is_string($url) || $url === '') {
            $url = (string) ($this->call('POST', '/transactions/' . $tx['id'] . '/token')['body']['url'] ?? '');
        }
        if ($url === '') {
            throw new GatewayException('FedaPay : lien de paiement absent');
        }
        return ['provider_ref' => (string) $tx['id'], 'payment_url' => $url];
    }

    public function status(array $payment): array
    {
        $path = $payment['provider_ref'] !== null
            ? '/transactions/' . rawurlencode((string) $payment['provider_ref'])
            : '/transactions/merchant/' . rawurlencode($payment['reference']);
        $r = $this->call('GET', $path);
        $tx = $r['body']['v1/transaction'] ?? null;
        if (!is_array($tx)) {
            if ($r['status'] === 404) {
                return ['state' => 'pending', 'amount' => null, 'provider_ref' => null, 'raw' => 'not_found'];
            }
            throw new GatewayException('FedaPay : statut illisible (' . $r['status'] . ')');
        }
        // La transaction doit porter NOTRE référence : on n'accepte pas le paiement d'une autre commande
        if (($tx['merchant_reference'] ?? $payment['reference']) !== $payment['reference']) {
            throw new GatewayException('FedaPay : la transaction ne correspond pas à cette référence');
        }
        $state = match ((string) $tx['status']) {
            'approved', 'transferred' => 'paid',
            'declined' => 'failed',
            'canceled', 'cancelled' => 'cancelled',
            'expired' => 'expired',
            'refunded' => 'refunded',
            default => 'pending',
        };
        return ['state' => $state, 'amount' => (int) $tx['amount'], 'provider_ref' => (string) $tx['id'], 'raw' => (string) $tx['status']];
    }

    public function refund(array $payment, int $amount): array
    {
        // L'API publique de FedaPay n'expose pas de remboursement : il se fait dans le tableau de bord
        return ['status' => 'pending', 'provider_ref' => null, 'message' => 'Remboursement à exécuter dans le tableau de bord FedaPay (transaction ' . $payment['provider_ref'] . ').'];
    }

    public function payout(int $amount, string $phone, string $name, string $reference): array
    {
        if ($this->isSandbox()) {
            return ['status' => 'simulated', 'provider_ref' => 'sandbox_' . $reference, 'message' => 'Versement simulé (sandbox).'];
        }
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '-');
        $r = $this->call('POST', '/payouts', [
            'amount' => $amount,
            'currency' => ['iso' => 'XOF'],
            'merchant_reference' => $reference,
            'customer' => ['firstname' => $first, 'lastname' => $last, 'phone_number' => ['number' => preg_replace('/^\+229/', '', $phone), 'country' => 'bj']],
        ]);
        $payout = $r['body']['v1/payout'] ?? null;
        if (!is_array($payout) || !isset($payout['id'])) {
            return ['status' => 'failed', 'provider_ref' => null, 'message' => 'FedaPay a refusé le versement (' . $r['status'] . ').'];
        }
        $start = $this->call('PUT', '/payouts/start', ['payouts' => [['id' => $payout['id']]]]);
        return $start['status'] >= 200 && $start['status'] < 300
            ? ['status' => 'paid', 'provider_ref' => (string) $payout['id'], 'message' => 'Versement envoyé.']
            : ['status' => 'failed', 'provider_ref' => (string) $payout['id'], 'message' => 'Versement créé mais non démarré (' . $start['status'] . ').'];
    }

    /** En-tête X-FEDAPAY-SIGNATURE : « t=<horodatage>,s=<hmac sha256 de "t.corps"> », 5 minutes de tolérance */
    public function parseWebhook(array $headers, string $rawBody): ?array
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = (string) ($headers['x-fedapay-signature'] ?? '');
        if ($secret === '' || $header === '') {
            return null;
        }
        $parts = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int) ($parts['t'][0] ?? 0);
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        $valid = false;
        foreach ($parts['s'] ?? [] as $candidate) {
            $valid = $valid || hash_equals($expected, $candidate);
        }
        if (!$valid || abs(time() - $t) > 300) {
            return null;
        }
        $event = json_decode($rawBody, true);
        $entity = $event['entity'] ?? [];
        return [
            'key' => ($event['name'] ?? 'event') . ':' . ($entity['id'] ?? '') . ':' . ($entity['updated_at'] ?? $t),
            'provider_ref' => isset($entity['id']) ? (string) $entity['id'] : null,
            'reference' => $entity['merchant_reference'] ?? ($entity['custom_metadata']['axi_reference'] ?? null),
        ];
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        $base = $this->isSandbox() ? 'https://sandbox-api.fedapay.com/v1' : 'https://api.fedapay.com/v1';
        $r = HttpClient::json($method, $base . $path, ['Authorization' => 'Bearer ' . $this->config['secret_key']], $body);
        if ($r['status'] === 0) {
            throw new GatewayException('FedaPay injoignable : ' . $r['raw']);
        }
        if ($r['status'] === 401) {
            throw new GatewayException('FedaPay a refusé la clé secrète : vérifiez une possible erreur de recopie dans config/config.local.php');
        }
        return $r;
    }
}
