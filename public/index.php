<?php
declare(strict_types=1);

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;

// Serveur intégré (php -S) : les fichiers existants (photos) sont servis directement
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

// Compression gzip si le serveur ne la fait pas déjà
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

// CORS pour Expo web : seules les origines de CORS_ORIGINS sont acceptées.
// L'application mobile n'envoie pas d'en-tête Origin et n'est pas concernée.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, Config::get('cors_origins', []), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, If-None-Match');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: ETag');
    header('Access-Control-Max-Age: 86400');
}
header('Vary: Origin', false);
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router = new Router();
    (require BASE_PATH . '/routes/api.php')($router);
    $router->dispatch(Request::fromGlobals());
} catch (HttpException $e) {
    Response::error($e->errorCode, $e->getMessage(), $e->status, $e->details, $e->headers);
} catch (Throwable $e) {
    Logger::write('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('SERVER_ERROR', Config::isLocal() ? $e->getMessage() : 'Erreur du serveur. Réessayez dans un instant.', 500);
}
