<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/** Passerelle injoignable, clé refusée ou réponse inattendue (message journalisé, jamais montré tel quel) */
final class GatewayException extends \RuntimeException
{
}
