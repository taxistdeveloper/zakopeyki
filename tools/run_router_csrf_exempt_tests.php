<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Router.php';

use App\Core\Router;

echo "=== QA-0007 Verification: Router CSRF Exemption for Micro-tasks API ===\n";

$router = new Router();
$reflection = new ReflectionClass($router);
$csrfExempt = $reflection->getMethod('csrfExempt');
$csrfExempt->setAccessible(true);

$passes = 0;
$total = 0;

function assertExempt(Router $router, ReflectionMethod $method, string $path, bool $expected, string $desc): void
{
    global $passes, $total;
    $total++;
    $result = $method->invoke($router, $path);
    if ($result === $expected) {
        $passes++;
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc (Expected: " . ($expected ? 'true' : 'false') . ", Got: " . ($result ? 'true' : 'false') . ")\n";
    }
}

// 1. Micro-tasks endpoints must be exempt from browser session CSRF enforcement
assertExempt($router, $csrfExempt, '/api/v1/micro-tasks/create', true, '/api/v1/micro-tasks/create is CSRF exempt');
assertExempt($router, $csrfExempt, '/api/v1/micro-tasks/12/offer', true, '/api/v1/micro-tasks/12/offer is CSRF exempt');
assertExempt($router, $csrfExempt, '/api/v1/micro-tasks/offers/5/select', true, '/api/v1/micro-tasks/offers/5/select is CSRF exempt');
assertExempt($router, $csrfExempt, '/api/v1/micro-tasks/12/complete', true, '/api/v1/micro-tasks/12/complete is CSRF exempt');
assertExempt($router, $csrfExempt, '/api/v1/micro-tasks/12/review', true, '/api/v1/micro-tasks/12/review is CSRF exempt');
assertExempt($router, $csrfExempt, '/api/v1/micro-tasks/12/cancel', true, '/api/v1/micro-tasks/12/cancel is CSRF exempt');

// 2. Regular web forms must NOT be exempt (CSRF enforced)
assertExempt($router, $csrfExempt, '/cart/add', false, '/cart/add requires CSRF token');
assertExempt($router, $csrfExempt, '/profile/delete-lot', false, '/profile/delete-lot requires CSRF token');
assertExempt($router, $csrfExempt, '/orders/checkout', false, '/orders/checkout requires CSRF token');

echo "\nSummary: $passes / $total tests passed.\n";
if ($passes === $total) {
    echo "SUCCESS: QA-0007 Micro-tasks API CSRF exemption verified!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}

