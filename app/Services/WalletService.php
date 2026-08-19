<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Заморозка/списание платы за отклик и выплата по микрозадаче.
 * Работает с таблицей wallets (не отдельный user_wallets).
 */
class WalletService
{
    public const RESPONSE_FEE = 50;

    private PDO $pdo;
    private Wallet $wallet;

    public function __construct(PDO $pdo, ?Wallet $wallet = null)
    {
        $this->pdo = $pdo;
        $this->wallet = $wallet ?? new Wallet();
    }

    public function holdResponseFee(int $userId, float $amount = 50.00): bool
    {
        $amountInt = $this->toInt($amount);
        if ($amountInt <= 0) {
            throw new InvalidArgumentException('Сумма заморозки должна быть строго больше 0.');
        }

        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $row = $this->lockWallet($userId);
            if ($row['balance'] < $amountInt) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $newBalance = $row['balance'] - $amountInt;
            $newHeld = $row['held_balance'] + $amountInt;
            $this->updateWallet($userId, $newBalance, $newHeld);
            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_HOLD_RESPONSE_FEE,
                -$amountInt,
                $newBalance,
                null
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Ошибка при заморозке средств: ' . $e->getMessage(), 0, $e);
        }
    }

    public function refundResponseFee(int $userId, int $taskId): bool
    {
        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $stmtOffer = $this->pdo->prepare("
                SELECT `id`, `response_fee_status`
                FROM `micro_task_offers`
                WHERE `task_id` = :task_id AND `executor_id` = :executor_id AND `response_fee_status` = 'held'
                FOR UPDATE
            ");
            $stmtOffer->execute([
                'task_id' => $taskId,
                'executor_id' => $userId,
            ]);
            $offer = $stmtOffer->fetch(PDO::FETCH_ASSOC);

            if (!$offer) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $refundAmount = self::RESPONSE_FEE;
            $row = $this->lockWallet($userId);
            if ($row['held_balance'] < $refundAmount) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $newBalance = $row['balance'] + $refundAmount;
            $newHeld = $row['held_balance'] - $refundAmount;
            $this->updateWallet($userId, $newBalance, $newHeld);

            $stmtUpdateOffer = $this->pdo->prepare("
                UPDATE `micro_task_offers`
                SET `response_fee_status` = 'refunded', `status` = IF(`status` = 'pending', 'rejected', `status`)
                WHERE `id` = :id
            ");
            $stmtUpdateOffer->execute(['id' => $offer['id']]);

            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_UNHOLD_RESPONSE_FEE,
                $refundAmount,
                $newBalance,
                $taskId
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Ошибка при возврате платы за отклик: ' . $e->getMessage(), 0, $e);
        }
    }

    public function chargeResponseFee(int $userId, int $taskId): bool
    {
        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $stmtOffer = $this->pdo->prepare("
                SELECT `id`, `response_fee_status`
                FROM `micro_task_offers`
                WHERE `task_id` = :task_id AND `executor_id` = :executor_id AND `response_fee_status` = 'held'
                FOR UPDATE
            ");
            $stmtOffer->execute([
                'task_id' => $taskId,
                'executor_id' => $userId,
            ]);
            $offer = $stmtOffer->fetch(PDO::FETCH_ASSOC);

            if (!$offer) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $chargeAmount = self::RESPONSE_FEE;
            $row = $this->lockWallet($userId);
            if ($row['held_balance'] < $chargeAmount) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $newHeld = $row['held_balance'] - $chargeAmount;
            $this->updateWallet($userId, $row['balance'], $newHeld);

            $stmtUpdateOffer = $this->pdo->prepare("
                UPDATE `micro_task_offers`
                SET `response_fee_status` = 'charged'
                WHERE `id` = :id
            ");
            $stmtUpdateOffer->execute(['id' => $offer['id']]);

            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_CHARGE_RESPONSE_FEE,
                -$chargeAmount,
                $row['balance'],
                $taskId
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Ошибка при окончательном списании комиссии отклика: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Возврат 50 ₸, если оффер ещё не создан (ошибка до INSERT). */
    public function refundOrphanHold(int $userId, float $amount = 50.00): bool
    {
        $amountInt = $this->toInt($amount);
        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }
            $row = $this->lockWallet($userId);
            if ($row['held_balance'] < $amountInt) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }
            $newBalance = $row['balance'] + $amountInt;
            $newHeld = $row['held_balance'] - $amountInt;
            $this->updateWallet($userId, $newBalance, $newHeld);
            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_UNHOLD_RESPONSE_FEE,
                $amountInt,
                $newBalance,
                null
            );
            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Ошибка возврата депозита: ' . $e->getMessage(), 0, $e);
        }
    }

    public function processTaskPayout(int $taskId, int $executorId, float $finalPrice, float $feePercent = 10.00): bool
    {
        $finalInt = $this->toInt($finalPrice);
        if ($finalInt <= 0) {
            throw new InvalidArgumentException('Сумма заказа должна быть строго больше 0.');
        }

        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $platformFee = (int) round($finalInt * ($feePercent / 100.00));
            $executorPayout = $finalInt - $platformFee;

            $row = $this->lockWallet($executorId);
            $newBalance = $row['balance'] + $executorPayout;
            $this->updateWallet($executorId, $newBalance, $row['held_balance']);

            $this->wallet->logTaskTx(
                $executorId,
                Wallet::TYPE_TASK_PAYOUT,
                $executorPayout,
                $newBalance,
                $taskId
            );
            $this->wallet->logTaskTx(
                0,
                Wallet::TYPE_PLATFORM_COMMISSION,
                $platformFee,
                0,
                $taskId
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Ошибка при проведении выплаты за задачу: ' . $e->getMessage(), 0, $e);
        }
    }

    public function holdCustomerBudget(int $userId, int $taskId, int $amount, ?string $rrn = null): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $row = $this->lockWallet($userId);
            if ($row['balance'] < $amount) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $newBalance = $row['balance'] - $amount;
            $newHeld = $row['held_balance'] + $amount;
            $this->updateWallet($userId, $newBalance, $newHeld);
            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_MICRO_ESCROW_HOLD,
                -$amount,
                $newBalance,
                $taskId,
                $rrn
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Ошибка холда бюджета задачи: ' . $e->getMessage(), 0, $e);
        }
    }

    public function captureCustomerBudget(int $userId, int $taskId, int $amount): bool
    {
        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $row = $this->lockWallet($userId);
            if ($row['held_balance'] < $amount) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $newHeld = $row['held_balance'] - $amount;
            $this->updateWallet($userId, $row['balance'], $newHeld);
            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_MICRO_ESCROW_RELEASE,
                -$amount,
                $row['balance'],
                $taskId
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    public function refundCustomerBudget(int $userId, int $taskId, int $amount): bool
    {
        $ownTx = !$this->pdo->inTransaction();
        try {
            if ($ownTx) {
                $this->pdo->beginTransaction();
            }

            $row = $this->lockWallet($userId);
            if ($row['held_balance'] < $amount) {
                if ($ownTx) {
                    $this->pdo->rollBack();
                }
                return false;
            }

            $newBalance = $row['balance'] + $amount;
            $newHeld = $row['held_balance'] - $amount;
            $this->updateWallet($userId, $newBalance, $newHeld);
            $this->wallet->logTaskTx(
                $userId,
                Wallet::TYPE_MICRO_ESCROW_RELEASE,
                $amount,
                $newBalance,
                $taskId
            );

            if ($ownTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    /** @return array{balance: int, held_balance: int} */
    private function lockWallet(int $userId): array
    {
        $this->wallet->getOrCreate($userId);
        $stmt = $this->pdo->prepare('SELECT `balance`, `held_balance` FROM `wallets` WHERE `user_id` = :user_id FOR UPDATE');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $ins = $this->pdo->prepare('INSERT INTO `wallets` (`user_id`, `balance`, `held_balance`) VALUES (:user_id, 0, 0)');
            $ins->execute(['user_id' => $userId]);
            return ['balance' => 0, 'held_balance' => 0];
        }

        return [
            'balance' => (int) $row['balance'],
            'held_balance' => (int) ($row['held_balance'] ?? 0),
        ];
    }

    private function updateWallet(int $userId, int $balance, int $held): void
    {
        $stmt = $this->pdo->prepare('UPDATE `wallets` SET `balance` = :balance, `held_balance` = :held WHERE `user_id` = :user_id');
        $stmt->execute([
            'balance' => $balance,
            'held' => $held,
            'user_id' => $userId,
        ]);
    }

    private function toInt(float $amount): int
    {
        return (int) round($amount);
    }
}
