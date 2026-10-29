<?php
declare(strict_types=1);

namespace Axi\Core;

final class Request
{
    /** Paramètres extraits de l'URL, ex. {id} */
    public array $params = [];
    /** Utilisateur connecté, rempli par Auth */
    public ?array $user = null;
    public ?int $tokenId = null;

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        // Apache peut transmettre Authorization sous un autre nom après réécriture
        $headers['authorization'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        [$path, $base] = self::resolvePath();
        Url::setBase((string) (Config::get('public_url') ?: self::origin() . $base));

        return new self($method, $path, $_GET, self::parseBody(), $headers);
    }

    /**
     * Chemin relatif à l'application, que l'API soit à la racine ou dans un sous-dossier.
     * Renvoie [chemin, préfixe retiré].
     */
    private static function resolvePath(): array
    {
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $script = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        foreach ([$script, (string) preg_replace('#/public$#', '', $script)] as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
                return ['/' . trim(substr($path, strlen($prefix)), '/'), $prefix];
            }
        }
        return ['/' . trim($path, '/'), ''];
    }

    private static function origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private static function parseBody(): array
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === '' || $raw === false) {
                return [];
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new HttpException(400, 'INVALID_JSON', 'Requête invalide : le corps JSON est mal formé.');
            }
            return $data;
        }
        // Au-delà de post_max_size, PHP vide silencieusement $_POST et $_FILES
        if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_contains($type, 'multipart/form-data')) {
            throw new HttpException(413, 'FILE_TOO_LARGE', 'Fichier trop volumineux.');
        }
        return $_POST;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    /** Fichier envoyé en multipart/form-data, ou null */
    public function file(string $name): ?array
    {
        $file = $_FILES[$name] ?? null;
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    /**
     * Plusieurs fichiers : champ « photos[] » (tableau PHP) et/ou « photo », dans l'ordre d'envoi.
     * @return array<int, array>
     */
    public function files(string $multiple, string $single, int $max): array
    {
        $out = [];
        if (($one = $this->file($single)) !== null) {
            $out[] = $one;
        }
        $many = $_FILES[$multiple] ?? null;
        if (is_array($many) && is_array($many['name'] ?? null)) {
            foreach (array_keys($many['name']) as $i) {
                if (($many['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $out[] = ['name' => $many['name'][$i], 'type' => $many['type'][$i], 'tmp_name' => $many['tmp_name'][$i], 'error' => $many['error'][$i], 'size' => $many['size'][$i]];
                }
            }
        }
        if (count($out) > $max) {
            throw new HttpException(422, 'VALIDATION_ERROR', "$max photos maximum.", ['fields' => ['photos' => "$max photos maximum."]]);
        }
        return $out;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : null;
    }

    /** Identifiant préfixé de l'URL ({id} = « stl_214 ») ; 404 avec ce code s'il n'est pas valide */
    public function id(string $prefix, string $errorCode = 'NOT_FOUND', string $message = 'Ressource introuvable.'): int
    {
        return Ids::decode($prefix, $this->params['id'] ?? '', $errorCode, $message);
    }

    public function userId(): int
    {
        return (int) $this->user['id'];
    }

    public function role(): ?string
    {
        return $this->user['role'] ?? null;
    }

    public function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}
