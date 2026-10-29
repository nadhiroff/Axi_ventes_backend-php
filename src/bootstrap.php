<?php
declare(strict_types=1);

// Démarrage commun à l'API (public/index.php), aux tâches planifiées (cron/run.php) et aux scripts (database/)

use Axi\Core\Config;

define('BASE_PATH', dirname(__DIR__));

if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Dépendances absentes : lancez « composer install » dans ' . BASE_PATH . PHP_EOL);
}
require BASE_PATH . '/vendor/autoload.php';

Config::load(require BASE_PATH . '/config/config.php');

date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
