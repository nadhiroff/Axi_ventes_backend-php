<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/**
 * Prestataire Mobile Money (MTN, Moov, Celtiis). Un seul paiement par commande, pour tous les étals ;
 * l'argent est gardé par Axi (séquestre) jusqu'à la réception.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Déclenche la demande de validation sur le téléphone du client.
     * @return array{ref: string, instructions: string}
     */
    public function initiate(int $orderId, int $amount, string $operator, string $phone): array;

    /** @return 'pending'|'succeeded'|'failed' */
    public function status(string $ref): string;
}
