<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Routeur minimal. Le 3e argument définit l'accès :
 *   null              => route publique
 *   Router::OPTIONAL  => publique, mais l'utilisateur est reconnu s'il envoie son jeton
 *   []                => tout utilisateur connecté
 *   ['merchant']      => seulement ces rôles
 */
final class Router
{
    public const OPTIONAL = 'optional';

    private array $routes = [];

    public function get(string $pattern, array|\Closure $handler, array|string|null $access = null): void
    {
        $this->add('GET', $pattern, $handler, $access);
    }

    public function post(string $pattern, array|\Closure $handler, array|string|null $access = null): void
    {
        $this->add('POST', $pattern, $handler, $access);
    }

    public function put(string $pattern, array|\Closure $handler, array|string|null $access = null): void
    {
        $this->add('PUT', $pattern, $handler, $access);
    }

    public function patch(string $pattern, array|\Closure $handler, array|string|null $access = null): void
    {
        $this->add('PATCH', $pattern, $handler, $access);
    }

    public function delete(string $pattern, array|\Closure $handler, array|string|null $access = null): void
    {
        $this->add('DELETE', $pattern, $handler, $access);
    }

    private function add(string $method, string $pattern, array|\Closure $handler, array|string|null $access): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'access');
    }

    public function dispatch(Request $request): never
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }

            $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($route['access'] === self::OPTIONAL) {
                Auth::attempt($request);
            } elseif ($route['access'] !== null) {
                Auth::authenticate($request, $route['access']);
            }

            $handler = $route['handler'];
            if ($handler instanceof \Closure) {
                $handler($request);
            } else {
                [$class, $method] = $handler;
                (new $class())->$method($request);
            }
            // Un contrôleur termine toujours par Response::*
            throw new \LogicException('Le contrôleur n\'a envoyé aucune réponse');
        }

        if ($allowed) {
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'Méthode non autorisée.', [], ['Allow' => implode(', ', $allowed)]);
        }
        throw new HttpException(404, 'NOT_FOUND', 'Cette adresse n\'existe pas.');
    }
}
