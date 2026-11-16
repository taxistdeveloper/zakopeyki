<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Helpers\ActivityLogger;
use App\Services\AI\Core\RateLimiter;

/**
 * Ограничение частоты для чувствительных действий (вход, регистрация, сброс пароля,
 * 2FA, вступление в акции). Переиспользует существующий RateLimiter
 * (Redis → MySQL ai_rate_limits → файл) с отдельными лимитами на каждое действие.
 *
 * IP берётся через ActivityLogger::clientIp() (как в остальном проекте). Он доверяет
 * CF-Connecting-IP / X-Forwarded-For, поэтому для входа дополнительно лимитируется
 * идентификатор (email), который подделать нельзя.
 */
final class AuthThrottle
{
    /** action => [per_minute, per_hour] */
    public const LIMITS = [
        'login_ip' => [10, 60],
        'login_email' => [5, 20],
        'two_factor' => [5, 20],
        'register_ip' => [3, 10],
        'forgot_ip' => [3, 10],
        'forgot_email' => [2, 5],
        'verify_resend' => [1, 5],
        'contest_join' => [10, 60],
        'contest_report' => [3, 10],
        'contest_create' => [5, 20],
    ];

    /** @var array<string, RateLimiter> */
    private array $limiters = [];

    /**
     * @return array{allowed:bool, retry_after?:int}
     */
    public function hit(string $action, string $subject): array
    {
        if (!isset(self::LIMITS[$action])) {
            throw new \InvalidArgumentException('Unknown throttle action: ' . $action);
        }
        $key = 'thr_' . $action . ':' . substr(hash('sha256', mb_strtolower(trim($subject))), 0, 32);

        try {
            $res = $this->limiter($action)->attempt($key);
        } catch (\Throwable) {
            // Хранилище недоступно — не блокируем пользователя (fail-open), но фиксируем.
            ActivityLogger::warning('security.throttle_unavailable', 'Rate limiter недоступен: ' . $action);
            return ['allowed' => true];
        }

        if (empty($res['allowed'])) {
            ActivityLogger::warning('security.throttled', 'Превышена частота: ' . $action, null, null, [
                'action' => $action,
                'retry_after' => (int) ($res['retry_after'] ?? 60),
            ]);
            return ['allowed' => false, 'retry_after' => (int) ($res['retry_after'] ?? 60)];
        }

        return ['allowed' => true];
    }

    /** Проверить несколько ключей подряд; первый отказ останавливает проверку. */
    public function hitAll(array $pairs): array
    {
        foreach ($pairs as [$action, $subject]) {
            $r = $this->hit((string) $action, (string) $subject);
            if (!$r['allowed']) {
                return $r;
            }
        }
        return ['allowed' => true];
    }

    public static function ip(): string
    {
        return ActivityLogger::clientIp() ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function limiter(string $action): RateLimiter
    {
        if (!isset($this->limiters[$action])) {
            [$perMinute, $perHour] = self::LIMITS[$action];
            $this->limiters[$action] = new RateLimiter(['per_minute' => $perMinute, 'per_hour' => $perHour]);
        }
        return $this->limiters[$action];
    }
}
