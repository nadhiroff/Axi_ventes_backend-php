<?php
declare(strict_types=1);

namespace Axi\Core;

/**
 * Routeur minimal. Une route peut exiger une connexion :
 *   $roles = null      => route publique
 *   $roles = []        => tout utilisateur connecté
 *   $roles = ['merchant'] => seulement ces rôles
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array|\Closure $handler, ?array $roles = null): void
    {
        $this->add('GET', $pattern, $handler, $roles);
    }

    public function post(string $pattern, array|\Closure $handler, ?array $roles = null): void
    {
        $this->add('POST', $pattern, $handler, $roles);
    }

    public function put(string $pattern, array|\Closure $handler, ?array $roles = null): void
    {
        $this->add('PUT', $pattern, $handler, $roles);
    }

    public function patch(string $pattern, array|\Closure $handler, ?array $roles = null): void
    {
        $this->add('PATCH', $pattern, $handler, $roles);
    }

    public function delete(string $pattern, array|\Closure $handler, ?array $roles = null): void
    {
        $this->add('DELETE', $pattern, $handler, $roles);
    }

    private function add(string $method, string $pattern, array|\Closure $handler, ?array $roles): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'roles');
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
            if ($route['roles'] !== null) {
                Auth::authenticate($request, $route['roles']);
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
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'Méthode non autorisée', [], ['Allow' => implode(', ', $allowed)]);
        }
        throw new HttpException(404, 'NOT_FOUND', 'Route introuvable');
    }
}
