<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\Wallet;
use App\Services\MobileApi\TokenService;
use PDO;

/**
 * Удаление аккаунта по запросу пользователя (сайт и мобильное приложение; App Store 5.1.1(v),
 * Google Play — политика удаления аккаунта).
 *
 * Аккаунт НЕ удаляется физически: строка users обезличивается (имя, email, телефон, ИИН/БИН,
 * адреса, пароль, 2FA, связи с Google/Apple стираются), вход становится невозможен.
 * Причина: заказы, платежи, движения кошелька и отзывы ссылаются на пользователя без внешних
 * ключей — физическое удаление оставило бы «висячие» сделки у второй стороны и финансовые
 * записи без владельца. Срок хранения обезличенных финансовых записей — решение владельца
 * (RISKS_AND_OPEN_QUESTIONS Q11).
 *
 * Удаление блокируется, пока есть деньги на кошельке (в т.ч. удержанные) или незавершённые сделки —
 * иначе пользователь потеряет средства, а вторая сторона — возможность завершить сделку.
 */
final class AccountDeletionService
{
    public const REAUTH_WINDOW = 600;

    private const FINAL_ORDER_STATUSES = ['completed', 'refunded', 'partial_refunded', 'cancelled'];

    private PDO $db;
    private static bool $ensured = false;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Что мешает удалить аккаунт прямо сейчас.
     *
     * @return list<array{code: string, count?: int, amount?: int}>
     */
    public function blockers(int $userId): array
    {
        $out = [];
        try {
            $wallet = new Wallet();
            $available = $wallet->balance($userId);
            $held = $wallet->heldBalance($userId);
            if ($available + $held > 0) {
                $out[] = ['code' => 'wallet_not_empty', 'amount' => $available + $held];
            }
        } catch (\Throwable) {
            // нет таблиц кошелька — нет и денег
        }

        $placeholders = implode(',', array_fill(0, count(self::FINAL_ORDER_STATUSES), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM orders WHERE (buyer_id = ? OR seller_id = ?) AND status NOT IN ($placeholders)"
        );
        $stmt->execute(array_merge([$userId, $userId], self::FINAL_ORDER_STATUSES));
        $open = (int) $stmt->fetchColumn();
        if ($open > 0) {
            $out[] = ['code' => 'open_orders', 'count' => $open];
        }

        return $out;
    }

    /**
     * Обезличить аккаунт. Вызывать только после повторной аутентификации и проверки blockers().
     *
     * @return array{ok: true}|array{ok: false, error: string, blockers?: list<array<string, mixed>>}
     */
    public function delete(int $userId, string $channel): array
    {
        $user = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $user->execute([$userId]);
        $row = $user->fetch(PDO::FETCH_ASSOC);
        if (!$row || !empty($row['deleted_at'] ?? null)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $blockers = $this->blockers($userId);
        if ($blockers !== []) {
            return ['ok' => false, 'error' => 'deletion_blocked', 'blockers' => $blockers];
        }
        $this->ensureSchema();

        // Файлы и данные у внешних сервисов — до изменения БД (ошибки не блокируют удаление)
        $this->purgeFiles($userId, $row);
        try {
            (new \App\Services\TryOn\TryOnService())->purgeUser($userId);
        } catch (\Throwable $e) {
            error_log('[account-delete] tryon purge failed: ' . get_class($e));
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE users SET
                    name = 'Удалённый пользователь', first_name = 'Удалённый', last_name = 'пользователь',
                    login = CONCAT('deleted_', id), email = CONCAT('deleted+', id, '@deleted.invalid'),
                    google_id = NULL, apple_id = NULL, password = NULL,
                    two_factor_secret = NULL, two_factor_enabled = 0, two_factor_recovery_codes = NULL,
                    password_reset_token = NULL, password_reset_expires = NULL,
                    email_verify_token = NULL, email_verify_expires = NULL, remember_token = NULL,
                    avatar = 'У', avatar_file = NULL, phone = NULL, phone_visible = 0, bio = NULL,
                    iin = NULL, bin = NULL, business_name = NULL,
                    ship_country = NULL, ship_region = NULL, ship_city = NULL, ship_street = NULL,
                    ship_building = NULL, ship_apartment = NULL, ship_postal_code = NULL,
                    ship_contact_name = NULL, ship_phone = NULL,
                    site_access = 0, blocked_at = NOW(), blocked_reason = 'account_deleted', deleted_at = NOW()
                 WHERE id = ?"
            )->execute([$userId]);

            // Объявления снимаются с публикации (остаются для истории заказов покупателей)
            $this->db->prepare("UPDATE products SET status = 'archived' WHERE user_id = ? AND status <> 'sold'")
                ->execute([$userId]);

            // Личные данные без финансового значения — удаляются
            foreach ([
                'DELETE FROM favorites WHERE user_id = ?',
                'DELETE FROM user_follows WHERE follower_id = ? OR following_id = ?',
                'DELETE FROM stories WHERE user_id = ?',
                'DELETE FROM notifications WHERE user_id = ?',
                'DELETE FROM ai_memories WHERE user_id = ?',
                'DELETE FROM ai_user_preferences WHERE user_id = ?',
                'UPDATE seller_api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
            ] as $sql) {
                try {
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(array_fill(0, substr_count($sql, '?'), $userId));
                } catch (\PDOException) {
                    // таблица/колонка может отсутствовать на старой установке
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::exception($e, 'account.delete_failed');

            return ['ok' => false, 'error' => 'delete_failed'];
        }

        // Все мобильные сессии и устройства — сразу недействительны
        try {
            (new TokenService($this->db))->revokeAllForUser($userId, 'account_deleted');
        } catch (\Throwable) {
        }

        ActivityLogger::info('account.deleted', 'Аккаунт удалён пользователем', 'user', $userId, ['channel' => $channel]);

        return ['ok' => true];
    }

    public function hasPassword(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn() !== '';
    }

    /** @param array<string, mixed> $user */
    private function purgeFiles(int $userId, array $user): void
    {
        $root = dirname(__DIR__, 3) . '/public/uploads';
        if (!empty($user['avatar_file'])) {
            @unlink($root . '/avatars/' . basename((string) $user['avatar_file']));
        }
        try {
            $stmt = $this->db->prepare('SELECT image FROM stories WHERE user_id = ? AND image IS NOT NULL AND image <> \'\'');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $img) {
                @unlink($root . '/stories/' . basename((string) $img));
            }
        } catch (\Throwable) {
        }
        try {
            foreach (glob($root . '/business/biz_' . $userId . '_*') ?: [] as $doc) {
                @unlink($doc);
            }
        } catch (\Throwable) {
        }
    }

    private function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        $has = static fn (PDO $db, string $col): bool => (bool) $db->query("SHOW COLUMNS FROM users LIKE '{$col}'")->fetch();
        if (!$has($this->db, 'deleted_at')) {
            $this->db->exec('ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL');
        }
        if (!$has($this->db, 'apple_id')) {
            $this->db->exec('ALTER TABLE users ADD COLUMN apple_id VARCHAR(64) NULL DEFAULT NULL AFTER google_id');
            $this->db->exec('CREATE UNIQUE INDEX users_apple_id_unique ON users (apple_id)');
        }
        self::$ensured = true;
    }
}
