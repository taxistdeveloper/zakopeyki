<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Простой PSR-11-like DI-контейнер на массиве (без внешних библиотек).
 *
 * Сервисы регистрируются как ленивые фабрики и создаются один раз
 * при первом обращении (shared singletons).
 */
final class Container
{
    /** @var array<string, Closure(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, mixed> Скалярные параметры конфигурации */
    private array $parameters = [];

    /**
     * Регистрация ленивой фабрики сервиса.
     *
     * @param string $id Идентификатор (обычно FQCN сервиса)
     * @param Closure(Container): mixed $factory
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Регистрация готового экземпляра (используется в тестах для подмены).
     */
    public function instance(string $id, mixed $service): void
    {
        $this->instances[$id] = $service;
    }

    /**
     * Получение сервиса. Создаёт его при первом обращении.
     *
     * @throws RuntimeException если сервис не зарегистрирован
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered in the container.', $id));
        }

        $this->instances[$id] = ($this->factories[$id])($this);

        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    /**
     * Установка скалярного параметра конфигурации (например 'db.host').
     */
    public function setParameter(string $name, mixed $value): void
    {
        $this->parameters[$name] = $value;
    }

    /**
     * Чтение параметра конфигурации с необязательным значением по умолчанию.
     */
    public function parameter(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->parameters)
            ? $this->parameters[$name]
            : $default;
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
