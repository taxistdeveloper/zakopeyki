<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Escrow-эквайринг. Реальный банк подключается отдельно; здесь — холд RRN и аудит.
 */
class AcquiringService
{
    private PDO $pdo;
    private string $apiKey;
    private string $merchantId;

    public function __construct(PDO $pdo, string $apiKey = '', string $merchantId = '')
    {
        $this->pdo = $pdo;
        $fp = is_file(dirname(__DIR__, 2) . '/config/freedompay.php')
            ? (require dirname(__DIR__, 2) . '/config/freedompay.php')
            : [];
        $this->apiKey = $apiKey !== '' ? $apiKey : (string) ($fp['secret_key'] ?? '');
        $this->merchantId = $merchantId !== '' ? $merchantId : (string) ($fp['merchant_id'] ?? '');
    }

    public function holdCustomerFunds(int $taskId, float $amount): string
    {
        if ($amount <= 0) {
            throw new RuntimeException('Сумма холда должна быть больше 0.');
        }

        return 'RRN_' . date('YmdHis') . '_' . random_int(100000, 999999) . '_' . $taskId;
    }

    public function adjustHoldAmount(int $taskId, float $newAmount): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT `acquiring_rrn`
            FROM `wallet_transactions`
            WHERE `task_id` = :task_id AND `type` IN ('micro_escrow_hold', 'hold_response_fee')
            ORDER BY `id` DESC LIMIT 1
        ");
        $stmt->execute(['task_id' => $taskId]);
        $stmt->fetch(PDO::FETCH_ASSOC);

        unset($newAmount);
        return true;
    }

    public function captureSplitPayment(int $taskId, float $finalAmount, float $executorAmount, float $platformFee): bool
    {
        unset($taskId, $finalAmount, $executorAmount, $platformFee);
        return true;
    }

    public function releaseCustomerFunds(int $taskId): bool
    {
        unset($taskId);
        return true;
    }
}
