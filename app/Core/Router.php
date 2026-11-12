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

    /** REST DELETE (мобильные клиенты, /api/v1/try-on). CSRF проверяется так же, как для POST. */
    public function delete(string $path, array $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
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
                if (in_array(strtoupper($method), ['POST', 'DELETE'], true) && !$this->csrfExempt($path)) {
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
        if (str_starts_with($path, '/api/mobile/')) {
            \App\Controllers\Api\Mobile\BaseMobileController::send([
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => \App\Controllers\Api\Mobile\BaseMobileController::message('not_found')],
            ], 404);
        }
        View::render('errors/404', ['title' => 'Страница не найдена']);
    }

    private function csrfExempt(string $path): bool
    {
        $exempt = [
            '/payments/freedompay/result',
            '/payments/halyk/postlink',
            '/webhooks/cloudflare/stream',
            '/webhooks/delivery/status',
        ];
        if (in_array($path, $exempt, true)) {
            return true;
        }
        // Мобильное API (M-02): только Bearer-токены, cookie-сессия не используется → CSRF неприменим
        if (str_starts_with($path, '/api/mobile/')) {
            return true;
        }

        // API endpoints: micro-tasks and business (bearer or session auth handled in controller)
        if (str_starts_with($path, '/api/v1/micro-tasks/')) {
            return true;
        }

        // Seller API: Bearer-аутентификация вместо session CSRF
        if (str_starts_with($path, '/api/v1/business/')) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (is_string($auth) && preg_match('/Bearer\s+\S+/i', $auth)) {
                return true;
            }
        }

        return false;
    }
}
