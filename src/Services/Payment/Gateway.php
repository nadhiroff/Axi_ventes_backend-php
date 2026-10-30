<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/**
 * Passerelle Mobile Money. Tout le code appelant ne connaît que cette interface.
 * Règle anti double débit : create() reçoit la référence Axi fixe du paiement (payments.reference) ;
 * la passerelle doit la porter (merchant_reference FedaPay, partnerId KkiaPay) et ne jamais créer
 * deux transactions pour la même référence.
 */
interface Gateway
{
    public function name(): string;

    /**
     * Crée (ou retrouve) la transaction et renvoie l'adresse de paiement à ouvrir dans l'application.
     * @param array $payment ligne de la table payments ; $customer : first_name, last_name, email
     * @return array{provider_ref: ?string, payment_url: string}
     * @throws GatewayException passerelle injoignable ou clé refusée
     */
    public function create(array $payment, array $customer, string $returnUrl): array;

    /**
     * Statut vérifié serveur à serveur. Jamais d'après le seul retour de l'application.
     * @return array{state: 'pending'|'paid'|'failed'|'cancelled'|'expired'|'refunded', amount: ?int, provider_ref: ?string, raw: string}
     */
    public function status(array $payment): array;

    /** @return array{status: 'succeeded'|'pending'|'simulated'|'failed', provider_ref: ?string, message: string} */
    public function refund(array $payment, int $amount): array;

    /** Versement au commerçant. @return array{status: 'paid'|'simulated'|'failed', provider_ref: ?string, message: string} */
    public function payout(int $amount, string $phone, string $name, string $reference): array;

    /**
     * Webhook : vérifie la signature et renvoie l'événement, ou null si la signature est fausse.
     * @return array{key: string, provider_ref: ?string, reference: ?string}|null
     */
    public function parseWebhook(array $headers, string $rawBody): ?array;

    public function isSandbox(): bool;
}
