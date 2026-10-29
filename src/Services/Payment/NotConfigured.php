<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

use Axi\Core\HttpException;
use Axi\Core\Logger;

/** Fournisseur de paiement sans clés dans config.local.php : refusé avant toute écriture en base. */
final class NotConfigured
{
    public static function provider(string $name, string $configKey): HttpException
    {
        Logger::write('payments', "$name non configuré : renseigner $configKey dans config/config.local.php");
        return new HttpException(503, 'PROVIDER_NOT_CONFIGURED', "Le paiement par $name n'est pas encore disponible");
    }
}
