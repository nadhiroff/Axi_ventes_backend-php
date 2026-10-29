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

        return new self($method, self::resolvePath(), $_GET, self::parseBody(), $headers);
    }

    /** Chemin relatif à l'application, que l'API soit à la racine ou dans un sous-dossier. */
    private static function resolvePath(): string
    {
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        foreach ([$base, (string) preg_replace('#/public$#', '', $base)] as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }
        $path = '/' . trim($path, '/');
        return $path;
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
                throw new HttpException(400, 'INVALID_JSON', 'Corps JSON invalide');
            }
            return $data;
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

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : null;
    }
}
