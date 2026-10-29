<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

interface PaymentGateway
{
    /**
     * Prépare le paiement chez le fournisseur.
     * Renvoie [référence fournisseur ou null, données à transmettre à l'application].
     */
    public function init(array $payment, string $description): array;

    /**
     * Interroge le fournisseur : ['status' => 'approved'|'pending'|'failed', 'amount' => int].
     * On ne fait jamais confiance au contenu d'un webhook : on vérifie toujours ici.
     */
    public function fetchStatus(array $payment, string $providerRef): array;
}
