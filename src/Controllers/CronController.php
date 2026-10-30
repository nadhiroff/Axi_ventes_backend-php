<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Services\Nightly;

/** Tâche planifiée déclenchée par URL, pour les hébergements sans SSH (cron cPanel : wget ou curl) */
final class CronController
{
    public function run(Request $req): never
    {
        $key = (string) Config::get('cron_key');
        $given = (string) $req->query('key', '');
        // Clé absente ou trop courte : la route n'existe pas
        if (strlen($key) < 32 || !hash_equals($key, $given)) {
            throw new HttpException(404, 'NOT_FOUND', 'Cette adresse n\'existe pas.');
        }
        Response::ok(Nightly::run());
    }
}
