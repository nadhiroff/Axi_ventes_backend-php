<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/**
 * Prestataire Mobile Money (MTN, Moov, Celtiis). Un seul paiement par commande, pour tous les étals ;
 * l'argent est gardé par Axi (séquestre) jusqu'à la réception.
 *
 * Règle anti double débit, à respecter par toute implémentation réelle :
 * initiate() reçoit une référence Axi fixe pour la commande (« axi_pay_12 »). Elle doit être transmise
 * au prestataire comme référence marchande / clé d'idempotence, pour que deux appels avec la même
 * référence (nouvel essai après une coupure) ne déclenchent qu'UNE demande de paiement chez le client.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Déclenche (ou retrouve, si la référence existe déjà) la demande de validation sur le téléphone du client.
     * @return array{ref: string, instructions: string} ref = identifiant de la transaction chez le prestataire
     */
    public function initiate(string $reference, int $amount, string $operator, string $phone): array;

    /** @return 'pending'|'succeeded'|'failed' */
    public function status(string $ref): string;
}
