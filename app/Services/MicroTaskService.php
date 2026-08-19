<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\MicroTask;
use Exception;
use PDO;
use PDOException;
use RuntimeException;

class MicroTaskService
{
    public const RESPONSE_FEE = 50.00;
    public const MAX_PENDING_OFFERS = 5;
    public const PLATFORM_FEE_PERCENT = 10.00;

    private PDO $pdo;
    /** @var object */
    private $redis;
    private UnskilledTaskValidator $validator;
    private WalletService $walletService;
    private AcquiringService $acquiringService;

    public function __construct(
        PDO $pdo,
        object $redis,
        UnskilledTaskValidator $validator,
        WalletService $walletService,
        AcquiringService $acquiringService
    ) {
        $this->pdo = $pdo;
        $this->redis = $redis;
        $this->validator = $validator;
        $this->walletService = $walletService;
        $this->acquiringService = $acquiringService;
    }

    public static function make(): self
    {
        $pdo = Database::connect();
        (new MicroTask())->ensureSchema();
        return new self(
            $pdo,
            self::makeLockClient(),
            new UnskilledTaskValidator($pdo),
            new WalletService($pdo),
            new AcquiringService($pdo)
        );
    }

    private static function makeLockClient(): object
    {
        if (class_exists(\Redis::class)) {
            try {
                $redis = new \Redis();
                $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
                $port = (int) (getenv('REDIS_PORT') ?: 6379);
                if ($redis->connect($host, $port, 0.15)) {
                    return $redis;
                }
            } catch (\Throwable) {
            }
        }

        return new MicroTaskLock();
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, errors?: list<string>, task_id?: int, pin?: string}
     */
    public function createTask(int $customerId, array $data): array
    {
        $validation = $this->validator->validate(
            (string) ($data['title'] ?? ''),
            (string) ($data['description'] ?? ''),
            (int) ($data['category_id'] ?? 0)
        );

        if (!$validation['is_valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        $initialPrice = (float) ($data['initial_price'] ?? 0.00);
        if ($initialPrice <= 0) {
            return [
                'success' => false,
                'errors' => [t('gigs.err_price')],
            ];
        }

        $address = trim((string) ($data['address'] ?? ''));
        if ($address === '') {
            return [
                'success' => false,
                'errors' => [t('gigs.err_address')],
            ];
        }

        $pin = (string) random_int(1000, 9999);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+12 hours'));
        $priceInt = (int) round($initialPrice);

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                INSERT INTO `micro_tasks`
                (`customer_id`, `category_id`, `title`, `description`, `address`, `initial_price`, `completion_pin`, `status`, `expires_at`)
                VALUES (:customer_id, :category_id, :title, :description, :address, :initial_price, :pin, 'open', :expires_at)
            ");
            $stmt->execute([
                'customer_id' => $customerId,
                'category_id' => (int) $data['category_id'],
                'title' => trim((string) $data['title']),
                'description' => trim((string) $data['description']),
                'address' => $address,
                'initial_price' => $priceInt,
                'pin' => $pin,
                'expires_at' => $expiresAt,
            ]);

            $taskId = (int) $this->pdo->lastInsertId();

            $rrn = $this->acquiringService->holdCustomerFunds($taskId, $initialPrice);

            if (!$this->walletService->holdCustomerBudget($customerId, $taskId, $priceInt, $rrn)) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => [t('gigs.err_customer_funds', ['amount' => $priceInt])],
                ];
            }

            $updRrn = $this->pdo->prepare('UPDATE `micro_tasks` SET `acquiring_rrn` = :rrn WHERE `id` = :id');
            $updRrn->execute(['rrn' => $rrn, 'id' => $taskId]);

            $this->pdo->commit();

            return [
                'success' => true,
                'task_id' => $taskId,
                'pin' => $pin,
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'success' => false,
                'errors' => [t('gigs.err_create') . ' ' . $e->getMessage()],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function submitOffer(int $executorId, int $taskId, string $offerType, ?float $customPrice = null): array
    {
        $stmtCount = $this->pdo->prepare("
            SELECT COUNT(*) FROM `micro_task_offers`
            WHERE `executor_id` = :executor_id AND `status` = 'pending'
        ");
        $stmtCount->execute(['executor_id' => $executorId]);
        if ((int) $stmtCount->fetchColumn() >= self::MAX_PENDING_OFFERS) {
            return ['success' => false, 'error' => t('gigs.err_offer_limit')];
        }

        $stmtTask = $this->pdo->prepare('SELECT * FROM `micro_tasks` WHERE `id` = :id FOR UPDATE');
        $stmtTask->execute(['id' => $taskId]);
        $task = $stmtTask->fetch(PDO::FETCH_ASSOC);

        if (!$task || $task['status'] !== 'open') {
            return ['success' => false, 'error' => t('gigs.err_task_closed')];
        }

        if ((int) $task['customer_id'] === $executorId) {
            return ['success' => false, 'error' => t('gigs.err_own_task')];
        }

        if (strtotime((string) $task['expires_at']) <= time()) {
            return ['success' => false, 'error' => t('gigs.err_task_closed')];
        }

        if (!$this->walletService->holdResponseFee($executorId, self::RESPONSE_FEE)) {
            return ['success' => false, 'error' => t('gigs.err_response_fee')];
        }

        $initialPrice = (float) $task['initial_price'];
        $proposedPrice = $initialPrice;

        if ($offerType === 'discount_20') {
            $proposedPrice = round($initialPrice * 0.80, 2);
        } elseif ($offerType === 'raise_20') {
            $proposedPrice = round($initialPrice * 1.20, 2);
        } elseif ($offerType === 'custom') {
            if (!$customPrice || $customPrice <= 0) {
                $this->walletService->refundOrphanHold($executorId);
                return ['success' => false, 'error' => 'Некорректная сумма предложения.'];
            }
            $proposedPrice = round($customPrice, 2);
        }

        if ($offerType === 'discount_20') {
            $lockKey = 'micro_task_lock_' . $taskId;
            $isLocked = $this->redis->set($lockKey, (string) $executorId, ['NX', 'EX' => 10]);

            if ($isLocked) {
                try {
                    $this->pdo->beginTransaction();

                    $stmtLock = $this->pdo->prepare("
                        UPDATE `micro_tasks`
                        SET `status` = 'locked', `executor_id` = :executor_id, `final_price` = :final_price
                        WHERE `id` = :task_id AND `status` = 'open'
                    ");
                    $stmtLock->execute([
                        'executor_id' => $executorId,
                        'final_price' => (int) round($proposedPrice),
                        'task_id' => $taskId,
                    ]);

                    if ($stmtLock->rowCount() === 0) {
                        throw new RuntimeException('already_taken');
                    }

                    $stmtOffer = $this->pdo->prepare("
                        INSERT INTO `micro_task_offers`
                        (`task_id`, `executor_id`, `offer_type`, `proposed_price`, `response_fee_status`, `status`)
                        VALUES (:task_id, :executor_id, 'discount_20', :proposed_price, 'held', 'accepted')
                    ");
                    $stmtOffer->execute([
                        'task_id' => $taskId,
                        'executor_id' => $executorId,
                        'proposed_price' => (int) round($proposedPrice),
                    ]);

                    $this->walletService->chargeResponseFee($executorId, $taskId);

                    $stmtOthers = $this->pdo->prepare("
                        SELECT `executor_id` FROM `micro_task_offers`
                        WHERE `task_id` = :task_id AND `executor_id` != :executor_id AND `response_fee_status` = 'held'
                    ");
                    $stmtOthers->execute(['task_id' => $taskId, 'executor_id' => $executorId]);
                    $otherExecutors = $stmtOthers->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($otherExecutors as $otherId) {
                        $this->walletService->refundResponseFee((int) $otherId, $taskId);
                    }

                    $this->acquiringService->adjustHoldAmount($taskId, $proposedPrice);

                    $this->pdo->commit();

                    return [
                        'success' => true,
                        'instant_matched' => true,
                        'message' => 'Заказ успешно забронирован за вами по скидке -20%! Приступайте к выполнению.',
                        'final_price' => $proposedPrice,
                    ];
                } catch (Exception $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->walletService->refundResponseFee($executorId, $taskId);
                    return ['success' => false, 'error' => 'Ошибка применения Instant Match.'];
                }
            }
        }

        $stmtInsertOffer = $this->pdo->prepare("
            INSERT INTO `micro_task_offers`
            (`task_id`, `executor_id`, `offer_type`, `proposed_price`, `response_fee_status`, `status`)
            VALUES (:task_id, :executor_id, :offer_type, :proposed_price, 'held', 'pending')
        ");
        $stmtInsertOffer->execute([
            'task_id' => $taskId,
            'executor_id' => $executorId,
            'offer_type' => $offerType,
            'proposed_price' => (int) round($proposedPrice),
        ]);

        return [
            'success' => true,
            'instant_matched' => false,
            'message' => 'Отклик отправлен. 50 ₸ заморожены до решения заказчика.',
            'proposed_price' => $proposedPrice,
        ];
    }

    public function selectOffer(int $customerId, int $offerId): bool
    {
        try {
            $this->pdo->beginTransaction();

            $stmtOffer = $this->pdo->prepare("
                SELECT o.*, t.`customer_id`, t.`status` as `task_status`
                FROM `micro_task_offers` o
                JOIN `micro_tasks` t ON t.`id` = o.`task_id`
                WHERE o.`id` = :offer_id FOR UPDATE
            ");
            $stmtOffer->execute(['offer_id' => $offerId]);
            $offer = $stmtOffer->fetch(PDO::FETCH_ASSOC);

            if (!$offer || (int) $offer['customer_id'] !== $customerId || $offer['task_status'] !== 'open') {
                $this->pdo->rollBack();
                return false;
            }

            $taskId = (int) $offer['task_id'];
            $selectedExecutorId = (int) $offer['executor_id'];
            $finalPrice = (float) $offer['proposed_price'];

            $stmtTaskUpdate = $this->pdo->prepare("
                UPDATE `micro_tasks`
                SET `status` = 'locked', `executor_id` = :executor_id, `final_price` = :final_price
                WHERE `id` = :task_id
            ");
            $stmtTaskUpdate->execute([
                'executor_id' => $selectedExecutorId,
                'final_price' => (int) round($finalPrice),
                'task_id' => $taskId,
            ]);

            $stmtAccept = $this->pdo->prepare("UPDATE `micro_task_offers` SET `status` = 'accepted' WHERE `id` = :id");
            $stmtAccept->execute(['id' => $offerId]);

            $this->walletService->chargeResponseFee($selectedExecutorId, $taskId);

            $stmtRejectOthers = $this->pdo->prepare("
                SELECT `executor_id` FROM `micro_task_offers`
                WHERE `task_id` = :task_id AND `id` != :offer_id AND `response_fee_status` = 'held'
            ");
            $stmtRejectOthers->execute(['task_id' => $taskId, 'offer_id' => $offerId]);
            $rejectedExecutors = $stmtRejectOthers->fetchAll(PDO::FETCH_COLUMN);

            foreach ($rejectedExecutors as $rejectedId) {
                $this->walletService->refundResponseFee((int) $rejectedId, $taskId);
            }

            $this->acquiringService->adjustHoldAmount($taskId, $finalPrice);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function completeTaskWithPin(int $executorId, int $taskId, string $enteredPin): array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare('SELECT * FROM `micro_tasks` WHERE `id` = :id FOR UPDATE');
            $stmt->execute(['id' => $taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_not_found')];
            }

            if ((int) $task['executor_id'] !== $executorId) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_not_executor')];
            }

            if (!in_array($task['status'], ['locked', 'in_progress'], true)) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_bad_status')];
            }

            if (trim($enteredPin) !== (string) $task['completion_pin']) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_pin')];
            }

            $finalPrice = (float) ($task['final_price'] ?: $task['initial_price']);
            $platformFeePercent = (float) $task['platform_fee_percent'];

            $platformFee = round($finalPrice * ($platformFeePercent / 100.00), 2);
            $executorPayout = round($finalPrice - $platformFee, 2);

            $this->acquiringService->captureSplitPayment($taskId, $finalPrice, $executorPayout, $platformFee);
            $this->walletService->captureCustomerBudget((int) $task['customer_id'], $taskId, (int) round($finalPrice));
            $this->walletService->processTaskPayout($taskId, $executorId, $finalPrice, $platformFeePercent);

            $stmtComplete = $this->pdo->prepare("
                UPDATE `micro_tasks`
                SET `status` = 'completed'
                WHERE `id` = :id
            ");
            $stmtComplete->execute(['id' => $taskId]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => t('gigs.complete_ok'),
                'payout_amount' => $executorPayout,
                'platform_fee' => $platformFee,
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'error' => t('gigs.err_pin_generic') . ' ' . $e->getMessage()];
        }
    }

    /**
     * Отмена поручения заказчиком: возврат бюджета и залогов откликов.
     *
     * @return array{success: bool, error?: string, message?: string}
     */
    public function cancelTask(int $customerId, int $taskId): array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare('SELECT * FROM `micro_tasks` WHERE `id` = :id FOR UPDATE');
            $stmt->execute(['id' => $taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_not_found')];
            }

            if ((int) $task['customer_id'] !== $customerId) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_cancel_forbidden')];
            }

            if (!in_array($task['status'], ['open', 'locked', 'in_progress'], true)) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => t('gigs.err_cancel_status')];
            }

            $heldAmount = (int) ($task['initial_price'] ?? 0);

            $stmtHeld = $this->pdo->prepare("
                SELECT `executor_id` FROM `micro_task_offers`
                WHERE `task_id` = :task_id AND `response_fee_status` = 'held'
            ");
            $stmtHeld->execute(['task_id' => $taskId]);
            $heldExecutors = $stmtHeld->fetchAll(PDO::FETCH_COLUMN);

            foreach ($heldExecutors as $executorId) {
                $this->walletService->refundResponseFee((int) $executorId, $taskId);
            }

            $this->pdo->prepare("
                UPDATE `micro_task_offers`
                SET `status` = IF(`status` = 'pending', 'rejected', `status`)
                WHERE `task_id` = :task_id
            ")->execute(['task_id' => $taskId]);

            if ($heldAmount > 0) {
                $this->walletService->refundCustomerBudget($customerId, $taskId, $heldAmount);
            }

            $this->acquiringService->releaseCustomerFunds($taskId);

            $this->pdo->prepare("
                UPDATE `micro_tasks`
                SET `status` = 'cancelled'
                WHERE `id` = :id
            ")->execute(['id' => $taskId]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => t('gigs.cancel_ok'),
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'error' => t('gigs.err_cancel')];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpen(?int $categoryId = null): array
    {
        $this->expireOverdue();

        $sql = "
            SELECT t.`id`, t.`customer_id`, t.`category_id`, c.`name` as `category_name`,
                   t.`title`, t.`description`, t.`address`, t.`initial_price`, t.`status`,
                   t.`created_at`, t.`expires_at`
            FROM `micro_tasks` t
            JOIN `micro_categories` c ON c.`id` = t.`category_id`
            WHERE t.`status` = 'open' AND t.`expires_at` > NOW()
        ";

        $params = [];
        if ($categoryId && $categoryId > 0) {
            $sql .= ' AND t.`category_id` = :category_id';
            $params['category_id'] = $categoryId;
        }

        $sql .= ' ORDER BY t.`id` DESC LIMIT 50';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, c.`name` as `category_name`
            FROM `micro_tasks` t
            JOIN `micro_categories` c ON c.`id` = t.`category_id`
            WHERE t.`customer_id` = :uid OR t.`executor_id` = :uid2
            ORDER BY t.`id` DESC
            LIMIT 40
        ");
        $stmt->execute(['uid' => $userId, 'uid2' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function offersForCustomerTask(int $customerId, int $taskId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT o.*
            FROM `micro_task_offers` o
            JOIN `micro_tasks` t ON t.`id` = o.`task_id`
            WHERE o.`task_id` = :task_id AND t.`customer_id` = :customer_id AND o.`status` = 'pending'
            ORDER BY o.`id` DESC
        ");
        $stmt->execute(['task_id' => $taskId, 'customer_id' => $customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function categories(): array
    {
        (new MicroTask())->ensureSchema();
        $rows = $this->pdo->query('SELECT `id`, `name` FROM `micro_categories` WHERE `is_unskilled_only` = 1 ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    public function formatCatalog(array $tasks): array
    {
        $formatted = [];
        foreach ($tasks as $task) {
            $initialPrice = (float) $task['initial_price'];
            $discount20 = round($initialPrice * 0.80, 2);
            $raise20 = round($initialPrice * 1.20, 2);

            $formatted[] = [
                'id' => (int) $task['id'],
                'customer_id' => (int) $task['customer_id'],
                'category' => [
                    'id' => (int) $task['category_id'],
                    'name' => $task['category_name'],
                ],
                'title' => $task['title'],
                'description' => $task['description'],
                'address' => $task['address'],
                'pricing' => [
                    'initial_price' => $initialPrice,
                    'net_payout_standard' => round($initialPrice * 0.90, 2),
                    'bargain_options' => [
                        'discount_20' => [
                            'price' => $discount20,
                            'net_payout' => round($discount20 * 0.90, 2),
                            'is_instant_match' => true,
                            'badge' => t('gigs.instant_badge'),
                        ],
                        'raise_20' => [
                            'price' => $raise20,
                            'net_payout' => round($raise20 * 0.90, 2),
                            'is_instant_match' => false,
                        ],
                    ],
                ],
                'fee_info' => [
                    'response_fee' => self::RESPONSE_FEE,
                    'platform_commission_percent' => self::PLATFORM_FEE_PERCENT,
                ],
                'created_at' => $task['created_at'],
                'expires_at' => $task['expires_at'],
            ];
        }

        return $formatted;
    }

    private function expireOverdue(): void
    {
        try {
            $this->pdo->exec("UPDATE `micro_tasks` SET `status` = 'expired' WHERE `status` = 'open' AND `expires_at` <= NOW()");
        } catch (PDOException) {
        }
    }
}
