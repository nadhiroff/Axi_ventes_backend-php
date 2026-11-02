<?php
declare(strict_types=1);

// Worker Axi : webhooks de paiement, emails, notifications du téléphone, réconciliation des paiements.
//   php cron/worker.php              tourne 55 s puis s'arrête (cron chaque minute : * * * * *)
//   php cron/worker.php --forever    tourne sans fin (service systemd, redémarré en cas d'arrêt)
//   php cron/worker.php --seconds=N  tourne N secondes
// Sans worker actif, l'API traite elle-même ses tâches après chaque réponse (plus lent, mais rien ne se perd).

use Axi\Core\Logger;
use Axi\Services\Worker;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$forever = in_array('--forever', $argv, true);
$seconds = 55;
foreach ($argv as $arg) {
    if (preg_match('/^--seconds=(\d+)$/', $arg, $m)) {
        $seconds = max(1, (int) $m[1]);
    }
}
$verbose = in_array('--verbose', $argv, true);
$log = $verbose ? static fn (string $line): int => print(gmdate('H:i:s') . " $line\n") : null;

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$running): void {
            $running = false;
        });
    }
}

do {
    try {
        $stats = Worker::run($forever ? 60 : $seconds, $log);
        if ($verbose || !$forever) {
            echo gmdate('c') . ' : ' . json_encode($stats) . "\n";
        }
    } catch (Throwable $e) {
        Logger::write('jobs', 'Worker arrêté : ' . get_class($e) . ': ' . $e->getMessage());
        // Base injoignable : on s'arrête, systemd ou le prochain cron relancera un worker neuf
        Worker::stop();
        exit(1);
    }
} while ($forever && $running);

if (!$forever) {
    // Lancé par cron : le suivant prend le relais dans quelques secondes, le signe de vie reste valable
    exit(0);
}
Worker::stop();
