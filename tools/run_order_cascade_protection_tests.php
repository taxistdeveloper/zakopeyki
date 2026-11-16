<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Models\Order;

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK  {$msg}\n";
        return;
    }
    $failed++;
    echo "FAIL {$msg}\n";
}

echo "=== Running QA-0003 Cascade Protection Tests ===\n";

// 1. Verify hasActiveOrdersForProduct method exists
$orderReflection = new ReflectionClass(Order::class);
assert_true(
    $orderReflection->hasMethod('hasActiveOrdersForProduct'),
    'Order::hasActiveOrdersForProduct method exists'
);

// 2. Удаление объявления (сайт и приложение) идёт через ListingService::delete — проверки там
$profileControllerCode = file_get_contents(dirname(__DIR__) . '/app/Controllers/ProfileController.php');
$listingServiceCode = file_get_contents(dirname(__DIR__) . '/app/Services/Listing/ListingService.php');
assert_true(
    str_contains($profileControllerCode, 'ListingService())->delete(')
        && str_contains($listingServiceCode, 'hasActiveOrdersForProduct'),
    'deleteLot → ListingService::delete checks hasActiveOrdersForProduct'
);

// 3. ListingService::delete checks active auction bids
assert_true(
    str_contains($listingServiceCode, 'countBids'),
    'ListingService::delete checks active auction bids'
);

// 4. Verify database schema for orders has ON DELETE RESTRICT
$schemaSql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$orderFkMatch = preg_match('/CREATE TABLE IF NOT EXISTS orders.*?FOREIGN KEY \(product_id\) REFERENCES products\(id\) ON DELETE RESTRICT/s', $schemaSql);
assert_true(
    $orderFkMatch === 1,
    'database/schema.sql defines orders.product_id with ON DELETE RESTRICT'
);

// 5. Verify migration file exists
assert_true(
    is_file(dirname(__DIR__) . '/database/migrate_fix_order_cascade.sql'),
    'database/migrate_fix_order_cascade.sql migration file exists'
);

echo "\nSummary: Passed: {$passed}, Failed: {$failed}\n";
if ($failed > 0) {
    exit(1);
}

