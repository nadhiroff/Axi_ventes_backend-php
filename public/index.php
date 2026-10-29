<?php
declare(strict_types=1);

use Axi\Core\Autoload;
use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;

define('BASE_PATH', dirname(__DIR__));

// Serveur intégré (php -S) : les fichiers existants (uploads) sont servis directement
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

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

// Compression gzip si le serveur ne la fait pas déjà
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

header('Access-Control-Allow-Origin: ' . Config::get('cors_origin'));
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    Response::noContent();
}

try {
    $router = new Router();
    (require BASE_PATH . '/routes/api.php')($router);
    $router->dispatch(Request::fromGlobals());
} catch (HttpException $e) {
    Response::error($e->errorCode, $e->getMessage(), $e->status, $e->details, $e->headers);
} catch (Throwable $e) {
    Logger::write('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('SERVER_ERROR', Config::isLocal() ? $e->getMessage() : 'Erreur interne, réessayez plus tard', 500);
}
