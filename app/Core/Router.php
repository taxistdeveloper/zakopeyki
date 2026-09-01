<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim($GLOBALS['appConfig']['url'] ?? '', '/');

        if ($base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route => $handler) {
            $pattern = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $route);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                if (strtoupper($method) === 'POST' && !$this->csrfExempt($path)) {
                    Csrf::enforce();
                }
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                [$class, $action] = $handler;
                $controller = new $class();
                call_user_func_array([$controller, $action], $params);
                return;
            }
        }

        http_response_code(404);
        View::render('errors/404', ['title' => 'Страница не найдена']);
    }

    private function csrfExempt(string $path): bool
    {
        $exempt = [
            '/payments/freedompay/result',
            '/webhooks/cloudflare/stream',
            '/webhooks/delivery/status',
        ];
        return in_array($path, $exempt, true);
    }
}
