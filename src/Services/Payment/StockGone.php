<?php
declare(strict_types=1);

namespace Axi\Services\Payment;

/** Interne : le stock d'une commande à réactiver n'est plus disponible (la transaction est annulée) */
final class StockGone extends \RuntimeException
{
}
