<?php
declare(strict_types=1);

namespace Axi\Support;

use Axi\Core\HttpException;
use Axi\Core\Request;

final class Merchant
{
    /** Étal du commerçant connecté ; sans étal, l'application ouvre l'écran « Créer mon étal » */
    public static function stall(Request $req): array
    {
        $stall = Stalls::findByUser($req->userId())
            ?? throw new HttpException(404, 'STALL_REQUIRED', 'Créez d\'abord votre étal pour accéder à cet espace.');
        if ($stall['status'] === 'suspended') {
            throw new HttpException(403, 'STALL_SUSPENDED', 'Votre étal est suspendu par l\'équipe Axi. Contactez un agent.');
        }
        return $stall;
    }

    /** « 1 client », « 4 clients » */
    public static function plural(int $n, string $singular, ?string $plural = null): string
    {
        return $n . ' ' . ($n > 1 ? ($plural ?? $singular . 's') : $singular);
    }
}
