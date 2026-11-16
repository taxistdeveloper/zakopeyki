<?php

declare(strict_types=1);

namespace App\Services\TryOn;

use App\Models\Setting;

/**
 * Настройки модуля: таблица settings (ключи tryon_*) поверх значений по умолчанию,
 * плюс config/tryon.php для технических параметров и секретов.
 */
final class TryOnSettings
{
    public const DEFAULTS = [
        'tryon_enabled' => '0',
        'tryon_beta_users' => '',
        'tryon_types_enabled' => 'upper,lower,dress',
        'tryon_providers_order' => 'genlook',
        'tryon_provider_genlook_enabled' => '1',
        'tryon_provider_fake_enabled' => '0',
        'tryon_user_daily_limit' => '3',
        'tryon_user_monthly_limit' => '20',
        'tryon_user_max_active' => '1',
        'tryon_max_concurrent' => '5',
        'tryon_monthly_budget_usd' => '50',
        'tryon_retention_hours' => '24',
        'tryon_consent_version' => '2026-11-v1',
    ];

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, string>|null $overrides тесты: значения вместо таблицы settings
     * @param array<string, mixed>|null $config тесты: вместо config/tryon.php
     */
    public function __construct(private readonly ?array $overrides = null, ?array $config = null)
    {
        $this->config = $config ?? (require dirname(__DIR__, 3) . '/config/tryon.php');
    }

    public function get(string $key): string
    {
        if ($this->overrides !== null && array_key_exists($key, $this->overrides)) {
            return (string) $this->overrides[$key];
        }
        $default = self::DEFAULTS[$key] ?? '';
        if ($this->overrides !== null) {
            return $default;
        }
        try {
            return (string) ((new Setting())->get($key, $default) ?? $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower(trim($this->get($key))), ['1', 'true', 'on', 'yes'], true);
    }

    /** Доступ пользователя: модуль включён либо пользователь в списке бета-тестеров. */
    public function isEnabledFor(?int $userId): bool
    {
        if ($this->bool('tryon_enabled')) {
            return true;
        }
        if ($userId === null || $userId <= 0) {
            return false;
        }
        $beta = array_map('intval', array_filter(array_map('trim', explode(',', $this->get('tryon_beta_users')))));

        return in_array($userId, $beta, true);
    }

    /** Модуль доступен хоть кому-то (для показа кнопки гостю). */
    public function isPubliclyEnabled(): bool
    {
        return $this->bool('tryon_enabled');
    }

    /** @return list<string> */
    public function enabledTypes(): array
    {
        return TryOnTypes::parseList($this->get('tryon_types_enabled'));
    }

    /** @return list<string> */
    public function providersOrder(): array
    {
        $out = [];
        foreach (explode(',', $this->get('tryon_providers_order')) as $p) {
            $p = trim($p);
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        // Fake всегда последним и только если явно разрешён.
        if ($this->allowFake() && $this->bool('tryon_provider_fake_enabled') && !in_array('fake', $out, true)) {
            $out[] = 'fake';
        }

        return $out;
    }

    public function isProviderEnabled(string $id): bool
    {
        if ($id === 'fake' && !$this->allowFake()) {
            return false;
        }

        return $this->bool('tryon_provider_' . $id . '_enabled');
    }

    public function retentionHours(): int
    {
        return max(1, min(24 * 7, $this->int('tryon_retention_hours')));
    }

    public function monthlyBudgetMicros(): int
    {
        return (int) round(max(0.0, (float) $this->get('tryon_monthly_budget_usd')) * 1_000_000);
    }

    public function consentVersion(): string
    {
        return $this->get('tryon_consent_version');
    }

    public function allowFake(): bool
    {
        return !empty($this->config['allow_fake']);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** @return array<string, mixed> */
    public function providerConfig(string $id): array
    {
        return (array) ($this->config['providers'][$id] ?? []);
    }

    public function storageDir(): string
    {
        return (string) ($this->config['storage_dir'] ?? dirname(__DIR__, 3) . '/storage/tryon');
    }

    /** externalUserId для провайдера: HMAC от user_id, без персональных данных. */
    public function externalUserId(int $userId): string
    {
        $secret = (string) ($this->config['user_hash_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('tryon: TRYON_USER_HASH_SECRET is not configured');
        }

        return 'zk' . substr(hash_hmac('sha256', 'tryon-user:' . $userId, $secret), 0, 30);
    }
}
