<?php
declare(strict_types=1);

namespace Axi\Controllers\Merchant;

use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Models\Stall;

/** Étal du commerçant connecté, partagé par les contrôleurs commerçant */
trait MerchantContext
{
    private function stall(Request $request): array
    {
        $stall = Stall::findByMerchant($request->userId());
        if ($stall === null) {
            throw new HttpException(404, 'STALL_NOT_FOUND', 'Créez d\'abord votre étal');
        }
        return $stall;
    }
}
