<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Logger;

/**
 * Prestataire factice (APP_ENV=local seulement) : le paiement est accepté dès la première
 * vérification de statut, comme si le client avait validé sur son téléphone.
 * Chaque demande de débit est journalisée dans storage/logs/payments.log : la simulation
 * vérifie ainsi qu'une commande n'est jamais débitée deux fois.
 * Numéro de test NETWORK_FAILURE_PHONE : le premier envoi échoue (coupure réseau simulée).
 */
final class FakePaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public const NETWORK_FAILURE_PHONE = '+2290199999999';

    public function initiate(string $reference, int $amount, string $operator, string $phone): array
    {
        $marker = BASE_PATH . '/storage/logs/fake-failure-' . $reference;
        if ($phone === self::NETWORK_FAILURE_PHONE && !is_file($marker)) {
            touch($marker);
            throw new \RuntimeException('Coupure réseau simulée');
        }
        // Même référence = même transaction, comme un vrai prestataire idempotent
        $ref = 'fake_' . $reference;
        Logger::write('payments', "DEMANDE DE DÉBIT $reference $amount FCFA $operator $phone → $ref");
        return [
            'ref' => $ref,
            'instructions' => 'Un message de validation arrive sur votre téléphone. Entrez votre code secret Mobile Money pour confirmer.',
        ];
    }

    public function status(string $ref): string
    {
        return 'succeeded';
    }
}
