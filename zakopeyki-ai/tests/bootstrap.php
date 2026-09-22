<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: автозагрузка + тестовый контейнер.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Глобальный доступ к тестовому контейнеру для тестов, которым он нужен.
$GLOBALS['test_container'] = require __DIR__ . '/../config/test_container.php';
