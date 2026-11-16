<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

use App\Services\TryOn\TryOnSettings;

/**
 * Выбор провайдера: порядок из настроек, включён, настроен, поддерживает тип, не исключён.
 */
final class TryOnProviderRouter
{
    /** @var array<string, TryOnProviderInterface> */
    private array $providers;

    /** @param array<string, TryOnProviderInterface>|null $providers тесты: готовые экземпляры */
    public function __construct(private readonly TryOnSettings $settings, ?array $providers = null)
    {
        $this->providers = $providers ?? $this->buildDefault();
    }

    /** @param list<string> $exclude */
    public function pick(string $tryonType, array $exclude = []): ?TryOnProviderInterface
    {
        foreach ($this->settings->providersOrder() as $id) {
            $p = $this->providers[$id] ?? null;
            if ($p === null || in_array($id, $exclude, true) || !$this->settings->isProviderEnabled($id)) {
                continue;
            }
            if ($p->isConfigured() && $p->supports($tryonType)) {
                return $p;
            }
        }

        return null;
    }

    public function get(string $id): ?TryOnProviderInterface
    {
        return $this->providers[$id] ?? null;
    }

    /** @return array<string, TryOnProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return array<string, TryOnProviderInterface> */
    private function buildDefault(): array
    {
        $out = ['genlook' => new GenlookProvider($this->settings->providerConfig('genlook'))];
        if ($this->settings->allowFake()) {
            $out['fake'] = new FakeTryOnProvider();
        }

        return $out;
    }
}
