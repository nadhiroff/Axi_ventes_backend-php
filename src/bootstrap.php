<?php
declare(strict_types=1);

// Démarrage commun à l'API (public/index.php) et aux tâches planifiées (cron/run.php)

use Axi\Core\Autoload;
use Axi\Core\Config;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/src/Core/Autoload.php';
Autoload::register(BASE_PATH . '/src');
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
