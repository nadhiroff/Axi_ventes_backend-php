<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Logger;
use Axi\Core\Url;

/**
 * Passerelle de TEST AUTOMATIQUE uniquement (env local, tests/simulation.php) : aucun argent.
 * Le paiement est accepté à la première vérification. Chaque création est journalisée dans
 * storage/logs/payments.log pour prouver qu'il n'y a jamais deux débits.
 * Téléphone NETWORK_FAILURE_PHONE : la première création échoue (coupure réseau simulée).
 */
final class FakeGateway implements Gateway
{
    public const NETWORK_FAILURE_PHONE = '+2290199999999';

    public function name(): string
    {
        return 'fake';
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function create(array $payment, array $customer, string $returnUrl): array
    {
        $marker = BASE_PATH . '/storage/logs/fake-failure-' . $payment['reference'];
        if ($payment['phone'] === self::NETWORK_FAILURE_PHONE && !is_file($marker)) {
            touch($marker);
            throw new GatewayException('Coupure réseau simulée');
        }
        Logger::write('payments', "DEMANDE DE DÉBIT {$payment['reference']} {$payment['amount']} FCFA");
        return ['provider_ref' => 'fake_' . $payment['reference'], 'payment_url' => Url::to('/v1/payments/return?ref=' . $payment['reference'])];
    }

    public function status(array $payment): array
    {
        return ['state' => 'paid', 'amount' => (int) $payment['amount'], 'provider_ref' => $payment['provider_ref'], 'raw' => 'fake_success'];
    }

    public function refund(array $payment, int $amount): array
    {
        return ['status' => 'simulated', 'provider_ref' => null, 'message' => 'Remboursement simulé (test).'];
    }

    public function payout(int $amount, string $phone, string $name, string $reference): array
    {
        return ['status' => 'simulated', 'provider_ref' => 'fake_' . $reference, 'message' => 'Versement simulé (test).'];
    }

    public function parseWebhook(array $headers, string $rawBody): ?array
    {
        return null;
    }
}
