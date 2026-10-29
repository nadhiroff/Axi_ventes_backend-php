<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/** Développement local uniquement : tout paiement vérifié est accepté. */
final class SimulationGateway implements PaymentGateway
{
    public function init(array $payment, string $description): array
    {
        return ['SIM-' . $payment['id'], ['simulation' => true]];
    }

    public function fetchStatus(array $payment, string $providerRef): array
    {
        return ['status' => 'approved', 'amount' => (int) $payment['amount']];
    }
}
