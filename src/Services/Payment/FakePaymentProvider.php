<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/**
 * Prestataire factice (APP_ENV=local seulement) : le paiement est accepté dès la première
 * vérification de statut, comme si le client avait validé sur son téléphone.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function initiate(int $orderId, int $amount, string $operator, string $phone): array
    {
        return [
            'ref' => 'fake_' . $orderId . '_' . bin2hex(random_bytes(4)),
            'instructions' => 'Un message de validation arrive sur votre téléphone. Entrez votre code secret Mobile Money pour confirmer.',
        ];
    }

    public function status(string $ref): string
    {
        return 'succeeded';
    }
}
