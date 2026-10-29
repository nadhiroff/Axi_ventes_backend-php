<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Logger;

final class Gateways
{
    /** Passerelle active (payments.provider), ou celle qui a créé un paiement existant */
    public static function get(?string $name = null): Gateway
    {
        $name ??= (string) Config::get('payments.provider', 'fedapay');
        try {
            return match ($name) {
                'fedapay' => new FedaPayGateway(),
                'kkiapay' => new KkiapayGateway(),
                'fake' => Config::isLocal() ? new FakeGateway() : throw new GatewayException('Passerelle de test interdite hors local'),
                default => throw new GatewayException("Passerelle inconnue : $name"),
            };
        } catch (GatewayException $e) {
            Logger::write('payments', 'CONFIGURATION : ' . $e->getMessage());
            throw new HttpException(503, 'PAYMENT_UNAVAILABLE', 'Le paiement Mobile Money est momentanément indisponible. Réessayez plus tard.');
        }
    }
}
