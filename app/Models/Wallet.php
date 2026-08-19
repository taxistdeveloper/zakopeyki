<?php

namespace App\Models;

use App\Core\Model;

class Wallet extends Model
{
    protected string $table = 'wallets';
    private static bool $ensured = false;

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAW = 'withdraw';
    public const TYPE_ESCROW_HOLD = 'escrow_hold';
    public const TYPE_ESCROW_RELEASE = 'escrow_release';
    public const TYPE_ESCROW_REFUND = 'escrow_refund';
    public const TYPE_AUCTION_HOLD = 'auction_hold';
    public const TYPE_AUCTION_REFUND = 'auction_refund';
    public const TYPE_LISTING_FEE = 'listing_fee';
    public const TYPE_HOLD_RESPONSE_FEE = 'hold_response_fee';
    public const TYPE_UNHOLD_RESPONSE_FEE = 'unhold_response_fee';
    public const TYPE_CHARGE_RESPONSE_FEE = 'charge_response_fee';
    public const TYPE_TASK_PAYOUT = 'task_payout';
    public const TYPE_PLATFORM_COMMISSION = 'platform_commission';
    public const TYPE_MICRO_ESCROW_HOLD = 'micro_escrow_hold';
    public const TYPE_MICRO_ESCROW_RELEASE = 'micro_escrow_release';

    public function __construct()
    {
        parent::__construct();
        $this->ensureTables();
    }

    public function getDb(): \PDO
    {
        return $this->db;
    }

    private function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS wallets (
                user_id INT UNSIGNED PRIMARY KEY,
                balance INT UNSIGNED NOT NULL DEFAULT 0,
                held_balance INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS wallet_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(40) NOT NULL,
                amount INT NOT NULL,
                balance_after INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED DEFAULT NULL,
                task_id INT UNSIGNED DEFAULT NULL,
                acquiring_rrn VARCHAR(64) DEFAULT NULL,
                meta VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_order (order_id),
                INDEX idx_task (task_id),
                INDEX idx_type (type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->ensureColumn('wallets', 'held_balance', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER balance');
        $this->ensureColumn('wallet_transactions', 'task_id', 'INT UNSIGNED DEFAULT NULL AFTER order_id');
        $this->ensureColumn('wallet_transactions', 'acquiring_rrn', 'VARCHAR(64) DEFAULT NULL AFTER task_id');

        self::$ensured = true;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $this->db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\PDOException) {
            // already exists
        }
    }

    /** @return array{user_id: int, balance: int, held_balance: int} */
    public function getOrCreate(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT user_id, balance, held_balance FROM wallets WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'user_id' => (int) $row['user_id'],
                'balance' => (int) $row['balance'],
                'held_balance' => (int) ($row['held_balance'] ?? 0),
            ];
        }

        $ins = $this->db->prepare('INSERT INTO wallets (user_id, balance, held_balance) VALUES (?, 0, 0)');
        $ins->execute([$userId]);
        return ['user_id' => $userId, 'balance' => 0, 'held_balance' => 0];
    }

    public function heldBalance(int $userId): int
    {
        return $this->getOrCreate($userId)['held_balance'];
    }

    public function balance(int $userId): int
    {
        return $this->getOrCreate($userId)['balance'];
    }

    /**
     * Пополнение (карта / Kaspi — симуляция).
     * @return array{ok: bool, balance?: int, error?: string}
     */
    public function deposit(int $userId, int $amount, string $source = 'card', ?string $meta = null): array
    {
        if ($amount < 100) {
            return ['ok' => false, 'error' => t('wallet.min_deposit')];
        }
        if ($amount > 5_000_000) {
            return ['ok' => false, 'error' => t('wallet.max_deposit')];
        }

        $ownTx = !$this->db->inTransaction();
        try {
            if ($ownTx) {
                $this->db->beginTransaction();
            }
            $newBalance = $this->applyCredit(
                $userId,
                $amount,
                self::TYPE_DEPOSIT,
                null,
                $meta ?? ('source:' . $source)
            );
            if ($ownTx) {
                $this->db->commit();
            }
            return ['ok' => true, 'balance' => $newBalance];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }
    }

    /**
     * Вывод на карту / Kaspi (симуляция).
     * @return array{ok: bool, balance?: int, error?: string}
     */
    public function withdraw(int $userId, int $amount, string $dest = 'card'): array
    {
        if ($amount < 100) {
            return ['ok' => false, 'error' => t('wallet.min_withdraw')];
        }

        $ownTx = !$this->db->inTransaction();
        try {
            if ($ownTx) {
                $this->db->beginTransaction();
            }
            $newBalance = $this->applyDebit(
                $userId,
                $amount,
                self::TYPE_WITHDRAW,
                null,
                'dest:' . $dest
            );
            if ($newBalance === null) {
                if ($ownTx) {
                    $this->db->rollBack();
                }
                return ['ok' => false, 'error' => t('wallet.insufficient')];
            }
            if ($ownTx) {
                $this->db->commit();
            }
            return ['ok' => true, 'balance' => $newBalance];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }
    }

    /**
     * Списать с кошелька в эскроу (внутри внешней транзакции заказа).
     * @return array{ok: bool, error?: string}
     */
    public function holdForEscrow(int $userId, int $amount, int $orderId): array
    {
        $after = $this->applyDebit($userId, $amount, self::TYPE_ESCROW_HOLD, $orderId, null);
        if ($after === null) {
            return ['ok' => false, 'error' => t('wallet.insufficient')];
        }
        return ['ok' => true];
    }

    /** Зачислить продавцу после разморозки эскроу. */
    public function releaseFromEscrow(int $sellerId, int $amount, int $orderId): void
    {
        $this->applyCredit($sellerId, $amount, self::TYPE_ESCROW_RELEASE, $orderId, null);
    }

    /** Вернуть покупателю с эскроу. */
    public function refundFromEscrow(int $buyerId, int $amount, int $orderId): void
    {
        $this->applyCredit($buyerId, $amount, self::TYPE_ESCROW_REFUND, $orderId, null);
    }

    /**
     * Списать плату за публикацию объявления.
     * @return array{ok: bool, balance?: int, error?: string}
     */
    public function chargeListingFee(int $userId, int $amount, ?int $productId = null): array
    {
        if ($amount <= 0) {
            return ['ok' => true, 'balance' => $this->balance($userId)];
        }

        $ownTx = !$this->db->inTransaction();
        try {
            if ($ownTx) {
                $this->db->beginTransaction();
            }
            $newBalance = $this->applyDebit(
                $userId,
                $amount,
                self::TYPE_LISTING_FEE,
                null,
                $productId ? ('listing:' . $productId) : 'listing'
            );
            if ($newBalance === null) {
                if ($ownTx) {
                    $this->db->rollBack();
                }
                return ['ok' => false, 'error' => t('wallet.insufficient')];
            }
            if ($ownTx) {
                $this->db->commit();
            }
            return ['ok' => true, 'balance' => $newBalance];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }
    }

    /** Заморозить средства под ставку. @return array{ok: bool, error?: string} */
    public function holdForAuction(int $userId, int $amount, int $productId): array
    {
        if ($amount <= 0) {
            return ['ok' => true];
        }
        $after = $this->applyDebit($userId, $amount, self::TYPE_AUCTION_HOLD, null, 'auction:' . $productId);
        if ($after === null) {
            return ['ok' => false, 'error' => t('wallet.insufficient')];
        }
        return ['ok' => true];
    }

    /** Вернуть заморозку предыдущему лидеру. @return array{ok: bool, error?: string} */
    public function refundAuctionHold(int $userId, int $amount, int $productId): array
    {
        if ($amount <= 0) {
            return ['ok' => true];
        }
        $this->applyCredit($userId, $amount, self::TYPE_AUCTION_REFUND, null, 'auction:' . $productId);
        return ['ok' => true];
    }

    /**
     * Оплата картой/Kaspi на чекауте: виртуально пополнить и сразу удержать в эскроу.
     * @return array{ok: bool, error?: string}
     */
    public function payExternalToEscrow(int $userId, int $amount, int $orderId, string $source): array
    {
        $this->applyCredit($userId, $amount, self::TYPE_DEPOSIT, $orderId, 'checkout:' . $source);
        $after = $this->applyDebit($userId, $amount, self::TYPE_ESCROW_HOLD, $orderId, 'checkout:' . $source);
        if ($after === null) {
            return ['ok' => false, 'error' => t('wallet.op_failed')];
        }
        return ['ok' => true];
    }

    /** @return list<array> */
    public function transactions(int $userId, int $limit = 50): array
    {
        $this->getOrCreate($userId);
        $stmt = $this->db->prepare(
            'SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array{balance: int, held_balance: int} */
    private function lockWalletRow(int $userId): array
    {
        $this->getOrCreate($userId);
        $stmt = $this->db->prepare('SELECT balance, held_balance FROM wallets WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return [
            'balance' => (int) ($row['balance'] ?? 0),
            'held_balance' => (int) ($row['held_balance'] ?? 0),
        ];
    }

    private function lockWallet(int $userId): int
    {
        return $this->lockWalletRow($userId)['balance'];
    }

    private function applyCredit(int $userId, int $amount, string $type, ?int $orderId, ?string $meta): int
    {
        $balance = $this->lockWallet($userId);
        $newBalance = $balance + $amount;
        $upd = $this->db->prepare('UPDATE wallets SET balance = ? WHERE user_id = ?');
        $upd->execute([$newBalance, $userId]);
        $this->logTx($userId, $type, $amount, $newBalance, $orderId, $meta);
        return $newBalance;
    }

    /** @return int|null новый баланс или null при нехватке */
    private function applyDebit(int $userId, int $amount, string $type, ?int $orderId, ?string $meta): ?int
    {
        $balance = $this->lockWallet($userId);
        if ($balance < $amount) {
            return null;
        }
        $newBalance = $balance - $amount;
        $upd = $this->db->prepare('UPDATE wallets SET balance = ? WHERE user_id = ?');
        $upd->execute([$newBalance, $userId]);
        $this->logTx($userId, $type, -$amount, $newBalance, $orderId, $meta);
        return $newBalance;
    }

    private function logTx(int $userId, string $type, int $signedAmount, int $balanceAfter, ?int $orderId, ?string $meta): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO wallet_transactions (user_id, type, amount, balance_after, order_id, task_id, acquiring_rrn, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $signedAmount, $balanceAfter, $orderId, null, null, $meta]);
    }

    public function logTaskTx(
        int $userId,
        string $type,
        int $signedAmount,
        int $balanceAfter,
        ?int $taskId,
        ?string $rrn = null,
        ?string $meta = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO wallet_transactions (user_id, type, amount, balance_after, order_id, task_id, acquiring_rrn, meta)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $signedAmount, $balanceAfter, $taskId, $rrn, $meta]);
    }

    public static function typeLabel(string $type): string
    {
        $key = 'wallet.type_' . $type;
        $label = t($key);
        return $label === $key ? $type : $label;
    }

    public static function formatMoney(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        return $sign . number_format(abs($amount), 0, '', ' ') . ' ₸';
    }
}
