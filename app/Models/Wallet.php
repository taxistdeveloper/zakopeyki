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
                meta VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_order (order_id),
                INDEX idx_type (type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$ensured = true;
    }

    /** @return array{user_id: int, balance: int} */
    public function getOrCreate(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT user_id, balance FROM wallets WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            return ['user_id' => (int) $row['user_id'], 'balance' => (int) $row['balance']];
        }

        $ins = $this->db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0)');
        $ins->execute([$userId]);
        return ['user_id' => $userId, 'balance' => 0];
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

    private function lockWallet(int $userId): int
    {
        $this->getOrCreate($userId);
        $stmt = $this->db->prepare('SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return (int) ($row['balance'] ?? 0);
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
            'INSERT INTO wallet_transactions (user_id, type, amount, balance_after, order_id, meta)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $signedAmount, $balanceAfter, $orderId, $meta]);
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
