<?php

namespace App\Helpers;

use App\Core\Auth;
use App\Models\ActivityLog;
use Throwable;

class ActivityLogger
{
    private static bool $writing = false;

    /**
     * @param array<string, mixed>|null $context
     */
    public static function log(
        string $action,
        string $message,
        string $level = 'info',
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $context = null,
        ?int $userId = null,
        ?string $userName = null
    ): void {
        if (self::$writing) {
            return;
        }

        try {
            self::$writing = true;

            if ($userId === null && Auth::check()) {
                $userId = Auth::id();
                $user = Auth::user();
                $userName = $userName ?? ($user['name'] ?? null);
            }

            (new ActivityLog())->write([
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => $action,
                'level' => $level,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'message' => $message,
                'context' => $context,
                'ip' => self::clientIp(),
                'user_agent' => self::userAgent(),
            ]);
        } catch (Throwable) {
            // Never break the main request because of logging.
        } finally {
            self::$writing = false;
        }
    }

    /** @param array<string, mixed>|null $context */
    public static function info(
        string $action,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $context = null
    ): void {
        self::log($action, $message, 'info', $entityType, $entityId, $context);
    }

    /** @param array<string, mixed>|null $context */
    public static function warning(
        string $action,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $context = null
    ): void {
        self::log($action, $message, 'warning', $entityType, $entityId, $context);
    }

    /** @param array<string, mixed>|null $context */
    public static function error(
        string $action,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $context = null
    ): void {
        self::log($action, $message, 'error', $entityType, $entityId, $context);
    }

    public static function exception(Throwable $e, string $action = 'system.exception'): void
    {
        self::error($action, mb_substr($e->getMessage(), 0, 400), null, null, [
            'class' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
        ]);
    }

    public static function registerHandlers(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            self::exception($e);
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка</title></head>';
            echo '<body style="font-family:sans-serif;padding:2rem"><h1>Что-то пошло не так</h1>';
            echo '<p>Ошибка записана в журнал. Попробуйте позже.</p></body></html>';
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $fatal = in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            $level = $fatal ? 'error' : 'warning';

            self::log('system.php_error', mb_substr($message, 0, 400), $level, null, null, [
                'severity' => $severity,
                'file' => $file,
                'line' => $line,
            ]);

            return false;
        });
    }

    public static function actionLabel(string $action): string
    {
        $key = 'admin.log_action_' . str_replace('.', '_', $action);
        $translated = t($key);
        if ($translated !== $key) {
            return $translated;
        }

        $prefix = strtok($action, '.') ?: $action;
        $prefixKey = 'admin.log_cat_' . $prefix;
        $prefixLabel = t($prefixKey);
        if ($prefixLabel !== $prefixKey) {
            return $prefixLabel . ' · ' . $action;
        }

        return $action;
    }

    private static function clientIp(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        foreach ($candidates as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return null;
    }

    private static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) && $ua !== '' ? $ua : null;
    }
}
