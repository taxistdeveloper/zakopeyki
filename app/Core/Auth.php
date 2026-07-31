<?php

namespace App\Core;

class Auth
{
    /** Разделы, которые админ может открыть/закрыть для менеджера */
    public const PERMISSIONS = [
        'products',
        'tickets',
        'ai_chats',
        'disputes',
    ];

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        self::setUser($user);
    }

    /** Обновить данные в сессии без ротации session id (например после правки профиля). */
    public static function refresh(array $user): void
    {
        self::start();
        self::setUser($user);
    }

    private static function setUser(array $user): void
    {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'avatar' => $user['avatar'] ?? 'U',
            'avatar_file' => $user['avatar_file'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'login' => $user['login'] ?? null,
            'phone' => $user['phone'] ?? null,
            'bio' => $user['bio'] ?? null,
            'site_access' => !empty($user['site_access']) ? 1 : 0,
            'permissions' => self::normalizePermissions($user['permissions'] ?? null, (string) ($user['role'] ?? 'user')),
        ];
    }

    /** @return list<string> */
    public static function normalizePermissions(mixed $raw, string $role = 'user'): array
    {
        if ($role === 'admin') {
            return self::PERMISSIONS;
        }
        if ($role !== 'manager') {
            return [];
        }

        $list = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = $decoded;
            }
        } elseif (is_array($raw)) {
            $list = $raw;
        }

        return array_values(array_intersect(self::PERMISSIONS, array_map('strval', $list)));
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /**
     * Доступ к сайту при stub_mode: админ всегда; остальные — с флагом site_access.
     * $refreshFromDb — подтянуть флаг из БД (чтобы выдача доступа работала без повторного входа).
     */
    public static function hasSiteAccess(bool $refreshFromDb = false): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        if (!self::check()) {
            return false;
        }

        if ($refreshFromDb) {
            try {
                $fresh = (new \App\Models\User())->find((int) self::id());
                if ($fresh) {
                    self::refresh($fresh);
                }
            } catch (\Throwable) {
                // keep session value
            }
        }

        return !empty(self::user()['site_access']);
    }

    public static function isManager(): bool
    {
        return (self::user()['role'] ?? '') === 'manager';
    }

    /** Админ или менеджер (сотрудник панели) */
    public static function isStaff(): bool
    {
        $role = self::user()['role'] ?? '';
        return $role === 'admin' || $role === 'manager';
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        if (!self::isManager()) {
            return false;
        }
        $perms = self::user()['permissions'] ?? [];
        return in_array($permission, $perms, true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . \App\Helpers\ProductHelper::url('/login'));
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public static function requireStaff(): void
    {
        self::requireLogin();
        if (!self::isStaff()) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }
}
