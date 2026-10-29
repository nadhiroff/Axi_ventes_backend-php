<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\HttpException;
use Axi\Core\Request;

final class Merchant
{
    /** Étal du commerçant connecté */
    public static function stall(Request $req): array
    {
        return Stalls::findByUser($req->userId())
            ?? throw new HttpException(404, 'STALL_NOT_FOUND', 'Votre étal n\'est pas encore ouvert sur Axi. Un agent doit d\'abord le vérifier.');
    }

    /** « 1 client », « 4 clients » */
    public static function plural(int $n, string $singular, ?string $plural = null): string
    {
        return $n . ' ' . ($n > 1 ? ($plural ?? $singular . 's') : $singular);
    }
}
