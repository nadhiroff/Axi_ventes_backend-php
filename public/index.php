<?php
declare(strict_types=1);

use Axi\Core\Config;
use Axi\Core\HttpException;
use Axi\Core\Logger;
use Axi\Core\Request;
use Axi\Core\Response;
use Axi\Core\Router;
use Axi\Web\WebError;

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
    header('Access-Control-Allow-Headers: Authorization, Content-Type, If-None-Match, Idempotency-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: ETag, Idempotent-Replayed');
    header('Access-Control-Max-Age: 86400');
}
header('Vary: Origin', false);
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Espace web partenaire (/pro et la racine) : réponses HTML ; tout le reste : API JSON
$isWeb = false;
try {
    $request = Request::fromGlobals();
    $isWeb = $request->path === '/' || $request->path === '/pro' || str_starts_with($request->path, '/pro/');
    $router = new Router();
    (require BASE_PATH . '/routes/' . ($isWeb ? 'web' : 'api') . '.php')($router);
    $router->dispatch($request);
} catch (HttpException $e) {
    if ($isWeb) {
        WebError::show($e->status, $e->getMessage());
    }
    Response::error($e->errorCode, $e->getMessage(), $e->status, $e->details, $e->headers);
} catch (Throwable $e) {
    Logger::write('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $message = Config::isLocal() ? $e->getMessage() : 'Erreur du serveur. Réessayez dans un instant.';
    if ($isWeb) {
        WebError::show(500, $message);
    }
    Response::error('SERVER_ERROR', $message, 500);
}
