<?php

declare(strict_types=1);

/**
 * Тестовый контейнер: строится на основе боевого, но переопределяет
 * окружение и внешние зависимости безопасными для тестов значениями.
 *
 * В тестах внешние сервисы подменяются через $container->instance(...)
 * с использованием PHPUnit createMock().
 */

use App\Core\Container;

/** @var Container $container */
$container = require __DIR__ . '/container.php';

// Тестовое окружение
$container->setParameter('app.env', 'testing');
$container->setParameter('app.debug', true);

// Отдельная тестовая БД и Redis DB, чтобы не трогать боевые данные
$container->setParameter('db.database', $container->parameter('db.database') . '_test');
$container->setParameter('redis.db', 15);

// Внешние API в тестах не вызываются: ключи намеренно пустые
$container->setParameter('openai.api_key', '');
$container->setParameter('platform.api_key', '');

return $container;
