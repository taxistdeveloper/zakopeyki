<?php

namespace App\Models;

use App\Core\Model;

class Bonus extends Model
{
    protected string $table = 'bonus_balances';
    private static bool $ensured = false;

    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_SALE = 'sale';
    public const TYPE_FOLLOWER = 'follower';
    public const TYPE_LISTING = 'listing';
    public const TYPE_REFERRAL = 'referral';

    /** First 500 users */
    public const REG_TIER1_LIMIT = 500;
    public const REG_TIER1_AMOUNT = 55000;

    /** Next 1000 users (501–1500) */
    public const REG_TIER2_LIMIT = 1500;
    public const REG_TIER2_AMOUNT = 25000;

    /** Everyone else */
    public const REG_DEFAULT_AMOUNT = 5000;

    public const AMOUNT_SALE = 100;
    public const AMOUNT_FOLLOWER = 1;
    public const AMOUNT_LISTING = 100;
    public const AMOUNT_REFERRAL = 500;

    /** Partner gym unlock threshold */
    public const GYM_THRESHOLD = 10000;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bonus_balances (
                user_id INT UNSIGNED PRIMARY KEY,
                balance INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bonus_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(40) NOT NULL,
                amount INT NOT NULL,
                balance_after INT UNSIGNED NOT NULL,
                ref_type VARCHAR(40) DEFAULT NULL,
                ref_id INT UNSIGNED DEFAULT NULL,
                meta VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_type (type),
                INDEX idx_ref (ref_type, ref_id),
                INDEX idx_created (created_at),
                UNIQUE KEY uq_award (user_id, type, ref_type, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $gymCol = $this->db->query("SHOW COLUMNS FROM bonus_balances LIKE 'gym_code'")->fetch();
        if (!$gymCol) {
            $this->db->exec(
                'ALTER TABLE bonus_balances ADD COLUMN gym_code VARCHAR(20) DEFAULT NULL UNIQUE AFTER balance'
            );
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS partner_gyms (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                address VARCHAR(255) NOT NULL,
                city VARCHAR(100) NOT NULL DEFAULT 'Караганда',
                phone VARCHAR(40) DEFAULT NULL,
                hours VARCHAR(120) DEFAULT NULL,
                perk VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->seedPartnerGyms();

        self::$ensured = true;
    }

    private function seedPartnerGyms(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) AS c FROM partner_gyms')->fetch()['c'];
        if ($count > 0) {
            return;
        }

        $seed = [
            ['FitZone Караганда', 'пр. Бухар Жырау, 49', 'Караганда', '+7 7212 00-00-01', '06:00–23:00', '1 бесплатное посещение / месяц', 10],
            ['Iron Hall', 'ул. Ермекова, 28', 'Караганда', '+7 7212 00-00-02', '07:00–22:00', 'Гостевой день по коду Zakopeyki', 20],
            ['SportLife Орбита', 'мкр. Орбита-1, 12', 'Караганда', '+7 7212 00-00-03', '08:00–22:00', 'Скидка на разовое посещение', 30],
            ['Pulse Gym Центр', 'ул. Гоголя, 35', 'Караганда', '+7 7212 00-00-04', '06:30–23:30', 'Доступ в тренажёрный зал по QR', 40],
        ];

        $ins = $this->db->prepare(
            'INSERT INTO partner_gyms (name, address, city, phone, hours, perk, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($seed as $row) {
            $ins->execute($row);
        }
    }

    /** @return array{user_id: int, balance: int} */
    public function getOrCreate(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT user_id, balance FROM bonus_balances WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            return ['user_id' => (int) $row['user_id'], 'balance' => (int) $row['balance']];
        }

        $ins = $this->db->prepare('INSERT INTO bonus_balances (user_id, balance) VALUES (?, 0)');
        $ins->execute([$userId]);
        return ['user_id' => $userId, 'balance' => 0];
    }

    public function balance(int $userId): int
    {
        return $this->getOrCreate($userId)['balance'];
    }

    public function canUseGym(int $userId): bool
    {
        return $this->balance($userId) >= self::GYM_THRESHOLD;
    }

    public static function registrationAmountForUserNumber(int $userNumber): int
    {
        if ($userNumber <= self::REG_TIER1_LIMIT) {
            return self::REG_TIER1_AMOUNT;
        }
        if ($userNumber <= self::REG_TIER2_LIMIT) {
            return self::REG_TIER2_AMOUNT;
        }
        return self::REG_DEFAULT_AMOUNT;
    }

    public static function format(int $amount): string
    {
        return number_format($amount, 0, '', ' ');
    }

    /**
     * @return array{ok: bool, balance?: int, amount?: int, error?: string, skipped?: bool}
     */
    public function award(
        int $userId,
        int $amount,
        string $type,
        ?string $refType = null,
        ?int $refId = null,
        ?string $meta = null
    ): array {
        if ($userId <= 0 || $amount <= 0) {
            return ['ok' => false, 'error' => 'bad_amount'];
        }

        $ownTx = !$this->db->inTransaction();
        try {
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            $this->getOrCreate($userId);

            $lock = $this->db->prepare('SELECT balance FROM bonus_balances WHERE user_id = ? FOR UPDATE');
            $lock->execute([$userId]);
            $row = $lock->fetch();
            $current = (int) ($row['balance'] ?? 0);

            $ins = $this->db->prepare(
                'INSERT INTO bonus_transactions (user_id, type, amount, balance_after, ref_type, ref_id, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $newBalance = $current + $amount;
            try {
                $ins->execute([
                    $userId,
                    $type,
                    $amount,
                    $newBalance,
                    $refType,
                    $refId,
                    $meta,
                ]);
            } catch (\PDOException $e) {
                // Duplicate unique award — already credited
                if ($ownTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return ['ok' => true, 'skipped' => true, 'balance' => $current, 'amount' => 0];
            }

            $upd = $this->db->prepare('UPDATE bonus_balances SET balance = ? WHERE user_id = ?');
            $upd->execute([$newBalance, $userId]);

            if ($ownTx) {
                $this->db->commit();
            }

            return ['ok' => true, 'balance' => $newBalance, 'amount' => $amount];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => 'op_failed'];
        }
    }

    /**
     * Welcome bonus by registration order (user id as queue number).
     * @return array{ok: bool, balance?: int, amount?: int, error?: string, skipped?: bool}
     */
    public function awardRegistration(int $userId): array
    {
        $amount = self::registrationAmountForUserNumber($userId);
        return $this->award(
            $userId,
            $amount,
            self::TYPE_REGISTRATION,
            'user',
            $userId,
            'welcome:' . $amount
        );
    }

    /** @return array{ok: bool, balance?: int, amount?: int, error?: string, skipped?: bool} */
    public function awardSale(int $sellerId, int $orderId): array
    {
        return $this->award(
            $sellerId,
            self::AMOUNT_SALE,
            self::TYPE_SALE,
            'order',
            $orderId,
            'sale'
        );
    }

    /** @return array{ok: bool, balance?: int, amount?: int, error?: string, skipped?: bool} */
    public function awardFollower(int $userId, int $followerId): array
    {
        return $this->award(
            $userId,
            self::AMOUNT_FOLLOWER,
            self::TYPE_FOLLOWER,
            'follower',
            $followerId,
            'follower'
        );
    }

    /** @return array{ok: bool, balance?: int, amount?: int, error?: string, skipped?: bool} */
    public function awardListing(int $userId, int $productId): array
    {
        return $this->award(
            $userId,
            self::AMOUNT_LISTING,
            self::TYPE_LISTING,
            'product',
            $productId,
            'listing'
        );
    }

    /** @return array{ok: bool, balance?: int, amount?: int, error?: string, skipped?: bool} */
    public function awardReferral(int $referrerId, int $newUserId): array
    {
        if ($referrerId <= 0 || $newUserId <= 0 || $referrerId === $newUserId) {
            return ['ok' => false, 'error' => 'bad_user'];
        }
        return $this->award(
            $referrerId,
            self::AMOUNT_REFERRAL,
            self::TYPE_REFERRAL,
            'user',
            $newUserId,
            'referral'
        );
    }

    /** @return list<array<string, mixed>> */
    public function transactions(int $userId, int $limit = 40): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, type, amount, balance_after, ref_type, ref_id, meta, created_at
             FROM bonus_transactions
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /** Remaining early-bird slots for UI. */
    public function earlyBirdStats(): array
    {
        $stmt = $this->db->query('SELECT COUNT(*) AS c FROM users');
        $total = (int) ($stmt->fetch()['c'] ?? 0);

        return [
            'total_users' => $total,
            'tier1_left' => max(0, self::REG_TIER1_LIMIT - $total),
            'tier2_left' => max(0, self::REG_TIER2_LIMIT - max($total, self::REG_TIER1_LIMIT)),
            'next_amount' => self::registrationAmountForUserNumber($total + 1),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function partnerGyms(): array
    {
        $stmt = $this->db->query(
            'SELECT id, name, address, city, phone, hours, perk
             FROM partner_gyms
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        return $stmt->fetchAll() ?: [];
    }

    public static function normalizeGymCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');
    }

    public static function formatGymCode(string $code): string
    {
        $raw = self::normalizeGymCode($code);
        if (str_starts_with($raw, 'ZK') && strlen($raw) === 10) {
            return 'ZK-' . substr($raw, 2, 4) . '-' . substr($raw, 6, 4);
        }
        return $raw;
    }

    private function generateGymCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $len = strlen($alphabet);
        do {
            $body = '';
            for ($i = 0; $i < 8; $i++) {
                $body .= $alphabet[random_int(0, $len - 1)];
            }
            $code = 'ZK' . $body;
            $stmt = $this->db->prepare('SELECT user_id FROM bonus_balances WHERE gym_code = ? LIMIT 1');
            $stmt->execute([$code]);
        } while ($stmt->fetch());

        return $code;
    }

    /**
     * Personal gym pass for users with enough bonuses.
     * @return array{code: string, display: string, qr_url: string, verify_url: string}|null
     */
    public function gymPass(int $userId): ?array
    {
        if (!$this->canUseGym($userId)) {
            return null;
        }

        $this->getOrCreate($userId);
        $stmt = $this->db->prepare('SELECT gym_code FROM bonus_balances WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $code = trim((string) ($row['gym_code'] ?? ''));

        if ($code === '') {
            $code = $this->generateGymCode();
            $upd = $this->db->prepare('UPDATE bonus_balances SET gym_code = ? WHERE user_id = ? AND (gym_code IS NULL OR gym_code = \'\')');
            $upd->execute([$code, $userId]);
            if ($upd->rowCount() === 0) {
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                $code = trim((string) ($row['gym_code'] ?? $code));
            }
        }

        $display = self::formatGymCode($code);
        $verifyPath = '/bonuses/verify/' . rawurlencode($display);
        $verifyUrl = $this->absoluteUrl($verifyPath);

        return [
            'code' => $code,
            'display' => $display,
            'verify_url' => $verifyUrl,
            'qr_url' => \App\Helpers\Totp::qrImageUrl($verifyUrl, 220),
        ];
    }

    /**
     * @return array{ok: bool, valid: bool, name?: string, balance?: int, code?: string, error?: string}
     */
    public function verifyGymPass(string $code): array
    {
        $normalized = self::normalizeGymCode($code);
        if ($normalized === '' || !preg_match('/^ZK[A-Z0-9]{8}$/', $normalized)) {
            return ['ok' => true, 'valid' => false, 'error' => 'bad_code'];
        }

        $stmt = $this->db->prepare(
            'SELECT b.user_id, b.balance, b.gym_code, u.name
             FROM bonus_balances b
             JOIN users u ON u.id = b.user_id
             WHERE b.gym_code = ?
             LIMIT 1'
        );
        $stmt->execute([$normalized]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => true, 'valid' => false, 'error' => 'not_found'];
        }

        $balance = (int) $row['balance'];
        if ($balance < self::GYM_THRESHOLD) {
            return [
                'ok' => true,
                'valid' => false,
                'name' => (string) $row['name'],
                'balance' => $balance,
                'code' => self::formatGymCode($normalized),
                'error' => 'threshold',
            ];
        }

        return [
            'ok' => true,
            'valid' => true,
            'name' => (string) $row['name'],
            'balance' => $balance,
            'code' => self::formatGymCode($normalized),
        ];
    }

    private function absoluteUrl(string $path): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . \App\Helpers\ProductHelper::url($path);
    }
}
