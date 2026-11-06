<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Services/EscrowService.php';

use App\Services\EscrowService;

echo "=== QA-0001 & QA-0002 Verification: Escrow Commission & 24h Window ===\n";

$passes = 0;
$total = 0;

function assertCondition(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    if ($cond) {
        $passes++;
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc\n";
    }
}

// 1. Inspect window constants
assertCondition(EscrowService::INSPECT_HOURS === 24, "EscrowService::INSPECT_HOURS is exactly 24 hours");
assertCondition(EscrowService::INSPECT_DAYS === 1, "EscrowService::INSPECT_DAYS is 1 day (backwards compatibility)");

// 2. Seller commission constants
assertCondition(EscrowService::SELLER_COMMISSION_PERCENT === 10, "EscrowService::SELLER_COMMISSION_PERCENT is 10%");
assertCondition(EscrowService::ARBITRATION_FEE_PERCENT === 3, "EscrowService::ARBITRATION_FEE_PERCENT is 3%");

// 3. Calculation tests
assertCondition(EscrowService::sellerCommission(10000) === 1000, "10% commission on 10 000 ₸ is 1 000 ₸");
assertCondition(EscrowService::sellerCommission(0) === 0, "10% commission on 0 ₸ is 0 ₸");
assertCondition(EscrowService::sellerCommission(5555) === 556, "10% commission on 5 555 ₸ rounded is 556 ₸");

// 4. Settle math integrity checks
$totalAmount = 10000;
$buyerRefund = 0;
$amountToSeller = $totalAmount - $buyerRefund;
$sellerCommission = EscrowService::sellerCommission($amountToSeller);
$payoutToSeller = $amountToSeller - $sellerCommission;
assertCondition($payoutToSeller === 9000 && $sellerCommission === 1000, "Full delivery: Seller receives 9 000 ₸ net (90%), Platform gets 1 000 ₸ (10%)");

// Partial settlement math check
$partialBuyerRefund = 4000;
$amountToSellerPartial = $totalAmount - $partialBuyerRefund;
$sellerCommissionPartial = EscrowService::sellerCommission($amountToSellerPartial);
$payoutToSellerPartial = $amountToSellerPartial - $sellerCommissionPartial;
assertCondition($payoutToSellerPartial === 5400 && $sellerCommissionPartial === 600, "Partial dispute: Buyer 4 000 ₸, Seller 5 400 ₸ (6000 - 10%), Platform 600 ₸");

// Full refund math check
$fullBuyerRefund = 10000;
$amountToSellerRefund = $totalAmount - $fullBuyerRefund;
$sellerCommissionRefund = EscrowService::sellerCommission($amountToSellerRefund);
$payoutToSellerRefund = $amountToSellerRefund - $sellerCommissionRefund;
assertCondition($payoutToSellerRefund === 0 && $sellerCommissionRefund === 0, "Full dispute refund: Seller 0 ₸, Platform 0 ₸, Buyer 10 000 ₸ + 300 ₸ fee");

echo "\nSummary: $passes / $total tests passed.\n";
if ($passes === $total) {
    echo "SUCCESS: Escrow Commission & 24h Inspection Window verified!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}

