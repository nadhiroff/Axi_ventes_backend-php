<?php
declare(strict_types=1);

// Tâche planifiée, une fois par nuit. Deux façons de la lancer :
//   php /chemin/cron/run.php
//   ou, sans SSH (cron cPanel) : wget -qO- "https://api.axi.bj/v1/internal/cron?key=<cron_key>"

use Axi\Services\Nightly;

require dirname(__DIR__) . '/src/bootstrap.php';

echo gmdate('c') . ' : ' . json_encode(Nightly::run()) . "\n";
