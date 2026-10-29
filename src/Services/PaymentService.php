<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Config;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Models\Payment;
use Axi\Models\Stall;
use Axi\Models\Subscription;
use Axi\Services\Payment\FedaPayGateway;
use Axi\Services\Payment\KkiapayGateway;
use Axi\Services\Payment\PaymentGateway;
use Axi\Services\Payment\SimulationGateway;

/**
 * Paiement séquestre : l'argent d'une commande reste « held » chez Axi
 * jusqu'à la confirmation de réception par le client, puis passe « released ».
 */
final class PaymentService
{
    /** Fournisseurs acceptés ; « simulation » seulement en local */
    public static function providers(): array
    {
        return Config::isLocal() ? ['fedapay', 'kkiapay', 'simulation'] : ['fedapay', 'kkiapay'];
    }

    public static function gateway(string $provider): PaymentGateway
    {
        return match ($provider) {
            'fedapay' => new FedaPayGateway(),
            'kkiapay' => new KkiapayGateway(),
            'simulation' => Config::isLocal() ? new SimulationGateway() : throw new HttpException(422, 'PROVIDER_NOT_ALLOWED', 'Moyen de paiement non disponible'),
            default => throw new HttpException(422, 'PROVIDER_NOT_ALLOWED', 'Moyen de paiement non disponible'),
        };
    }

    /** Crée le paiement et le prépare chez le fournisseur. Renvoie les données pour l'application. */
    public static function start(string $purpose, int $userId, int $amount, string $provider, ?int $orderId, ?int $stallId, string $description): array
    {
        $gateway = self::gateway($provider);
        $id = Payment::create($purpose, $userId, $amount, $provider, $orderId, $stallId);
        $payment = Payment::find($id);
        [$ref, $clientData] = $gateway->init($payment, $description);
        if ($ref !== null) {
            Payment::setRef($id, $ref);
        }
        return Payment::present($payment) + $clientData;
    }

    /**
     * Vérifie le paiement auprès du fournisseur et applique le résultat. Idempotent.
     * $providerRef : transactionId Kkiapay transmis par l'application.
     */
    public static function verify(array $payment, ?string $providerRef = null): array
    {
        if ($payment['status'] !== 'pending') {
            return $payment;
        }
        $ref = $payment['provider_ref'] ?? $providerRef;
        if ($ref === null || $ref === '') {
            throw new HttpException(422, 'TRANSACTION_ID_REQUIRED', 'Identifiant de transaction manquant');
        }
        if ($payment['provider_ref'] !== null && $providerRef !== null && $providerRef !== $payment['provider_ref']) {
            throw new HttpException(409, 'TRANSACTION_MISMATCH', 'Cette transaction ne correspond pas au paiement');
        }
        if ($payment['provider_ref'] === null) {
            $other = Payment::findByRef($payment['provider'], $ref);
            if ($other !== null && (int) $other['id'] !== (int) $payment['id']) {
                throw new HttpException(409, 'TRANSACTION_ALREADY_USED', 'Cette transaction a déjà été utilisée');
            }
        }

        $result = self::gateway($payment['provider'])->fetchStatus($payment, $ref);

        if ($result['status'] === 'approved') {
            if ($result['amount'] !== (int) $payment['amount']) {
                Logger::write('payments', "Montant incohérent paiement #{$payment['id']} : attendu {$payment['amount']}, reçu {$result['amount']}");
                Payment::transition((int) $payment['id'], 'pending', 'failed');
                throw new HttpException(409, 'AMOUNT_MISMATCH', 'Le montant payé ne correspond pas');
            }
            self::applySuccess($payment, $ref);
        } elseif ($result['status'] === 'failed') {
            Payment::transition((int) $payment['id'], 'pending', 'failed');
        }
        return Payment::find((int) $payment['id']);
    }

    private static function applySuccess(array $payment, string $ref): void
    {
        Database::transaction(static function () use ($payment, $ref): void {
            if ($payment['provider_ref'] === null) {
                Payment::setRef((int) $payment['id'], $ref);
            }
            $to = $payment['purpose'] === 'order' ? 'held' : 'succeeded';
            if (!Payment::transition((int) $payment['id'], 'pending', $to)) {
                return; // déjà traité par un autre appel (webhook + application)
            }
            if ($payment['purpose'] === 'order') {
                OrderService::markPaid((int) $payment['order_id']);
            } else {
                $stall = Stall::findRaw((int) $payment['stall_id']);
                Subscription::extend((int) $stall['id'], $stall['merchant_type'], (int) $payment['amount'], (int) $payment['id']);
            }
        });
    }
}
