<?php

declare(strict_types=1);

namespace App\Services\TargetPush;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\TargetPushCampaign;
use App\Services\Payment\FreedomPayGateway;
use App\Services\Payment\PaymentGatewayInterface;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Заказ, оплата и запись Target Push в существующие уведомления сайта.
 * Новые покупки и запуски при выключенной услуге не проходят.
 * Уже начатая отправка дописывает только получателей со статусом pending.
 */
final class TargetPushService
{
    private TargetPushCampaign $campaigns;
    private Setting $settings;
    private PaymentGatewayInterface $gateway;
    private PDO $db;

    public function __construct(
        ?TargetPushCampaign $campaigns = null,
        ?Setting $settings = null,
        ?PaymentGatewayInterface $gateway = null
    ) {
        $this->campaigns = $campaigns ?? new TargetPushCampaign();
        $this->settings = $settings ?? new Setting();
        $this->gateway = $gateway ?? new FreedomPayGateway();
        $this->db = Database::connect();
    }

    public function isEnabled(): bool
    {
        try {
            $this->settings->forget(TargetPushPolicy::SETTING_KEY);

            return TargetPushPolicy::resolveEnabled($this->settings->getBool(TargetPushPolicy::SETTING_KEY, false));
        } catch (Throwable) {
            return TargetPushPolicy::resolveEnabled(null, true);
        }
    }

    public function setEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!TargetPushPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning('target_push.feature_toggle_denied', 'Target Push не переключён: нет права администратора', 'settings', null, [
                'actor_id' => $actorId,
            ]);

            return false;
        }

        try {
            $before = $this->isEnabled();
            $this->settings->setBool(TargetPushPolicy::SETTING_KEY, $enabled);
            $audit = TargetPushPolicy::toggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Target Push включён' : 'Target Push выключен',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            ActivityLogger::warning('target_push.feature_toggle_failed', 'Не удалось переключить Target Push', 'settings', null);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function previewAudience(int $userId, string $category): array
    {
        $category = trim($category);
        if (!TargetPushPolicy::isCategoryKey($category)) {
            return ['ok' => false, 'error_code' => 'bad_category'];
        }
        $count = $this->campaigns->countAudience($category, $userId);

        return [
            'ok' => true,
            'count' => $count,
            'limit' => TargetPushPolicy::MAX_RECIPIENTS,
            'over' => $count > TargetPushPolicy::MAX_RECIPIENTS,
            'price' => TargetPushPolicy::PRICE_KZT,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $category = trim((string) ($input['category'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $url = trim((string) ($input['target_url'] ?? ''));
        $audience = TargetPushPolicy::isCategoryKey($category)
            ? $this->campaigns->countAudience($category, $userId)
            : 0;
        $block = TargetPushPolicy::createBlock(
            $this->isEnabled(),
            TargetPushPolicy::isCategoryKey($category),
            TargetPushPolicy::messageError($title, $body),
            TargetPushPolicy::targetUrlError($url),
            $audience
        );
        if ($block !== null) {
            return ['ok' => false, 'error_code' => $block, 'count' => $audience];
        }

        $id = $this->campaigns->insert([
            'public_id' => bin2hex(random_bytes(16)),
            'user_id' => $userId,
            'category_key' => $category,
            'title' => $title,
            'body' => $body,
            'target_url' => $url === '' ? null : $url,
            'amount' => TargetPushPolicy::serverQuote($input),
            'audience_count' => $audience,
            'status' => TargetPushPolicy::STATUS_MODERATION,
        ]);
        ActivityLogger::info('target_push.created', 'Target Push отправлен на модерацию', 'target_push', $id, [
            'category' => $category,
            'amount' => TargetPushPolicy::PRICE_KZT,
            'audience' => $audience,
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'amount' => TargetPushPolicy::PRICE_KZT,
            'count' => $audience,
            'status' => TargetPushPolicy::STATUS_MODERATION,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $userId, int $id, array $input): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error_code' => 'disabled'];
        }
        if (!TargetPushPolicy::mayEdit((string) $row['status'])) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $url = trim((string) ($input['target_url'] ?? ''));
        $messageError = TargetPushPolicy::messageError($title, $body);
        if ($messageError !== null) {
            return ['ok' => false, 'error_code' => $messageError];
        }
        $urlError = TargetPushPolicy::targetUrlError($url);
        if ($urlError !== null) {
            return ['ok' => false, 'error_code' => $urlError];
        }
        $this->campaigns->saveCreative($id, $title, $body, $url === '' ? null : $url, TargetPushPolicy::STATUS_MODERATION);

        return ['ok' => true, 'id' => $id, 'status' => TargetPushPolicy::STATUS_MODERATION];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $userId, int $id): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        if (!TargetPushPolicy::mayCancel((string) $row['status'])) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        if (TargetPushPolicy::consumesSlot((string) $row['status'])) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        $this->campaigns->saveCancelled($id);
        ActivityLogger::info('target_push.cancelled', 'Target Push отменён до запуска', 'target_push', $id, [
            'consumed_slot' => false,
            'refund' => false,
        ]);

        return ['ok' => true, 'status' => TargetPushPolicy::STATUS_CANCELLED];
    }

    /**
     * @return array<string, mixed>
     */
    public function moderate(int $id, bool $approve, string $reason = ''): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        if (!TargetPushPolicy::canToggle($role)) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $row = $this->campaigns->find($id);
        if (!$row || (string) $row['status'] !== TargetPushPolicy::STATUS_MODERATION) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        $reason = trim($reason);
        if (!$approve && ($reason === '' || mb_strlen($reason) > 255)) {
            return ['ok' => false, 'error_code' => 'bad_reason'];
        }
        $status = $approve ? TargetPushPolicy::STATUS_APPROVED : TargetPushPolicy::STATUS_REJECTED;
        $this->campaigns->saveModeration($id, $status, $approve ? null : $reason);
        ActivityLogger::info($approve ? 'target_push.approved' : 'target_push.rejected', 'Модерация Target Push', 'target_push', $id, [
            'status' => $status,
        ]);

        return ['ok' => true, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function beginPayment(int $userId, int $id, array $input = []): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $statusNow = (string) $row['status'];
        if (in_array($statusNow, [TargetPushPolicy::STATUS_PAID, TargetPushPolicy::STATUS_SENDING, TargetPushPolicy::STATUS_COMPLETED, TargetPushPolicy::STATUS_PARTIAL], true)) {
            return ['ok' => true, 'already_paid' => true, 'id' => $id, 'status' => $statusNow];
        }
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error_code' => 'disabled', 'id' => $id];
        }
        $idem = $this->normalizeIdempotencyKey($input['idempotency_key'] ?? null);
        if ($idem === false) {
            return ['ok' => false, 'error_code' => 'idempotency_conflict'];
        }
        if (is_string($idem)) {
            $existing = $this->campaigns->findByIdempotencyKey($idem);
            if ($existing && (int) $existing['id'] !== $id) {
                return ['ok' => false, 'error_code' => 'idempotency_conflict'];
            }
        }

        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById($id);
            if (!$locked || (int) $locked['user_id'] !== $userId) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'forbidden'];
            }
            $status = (string) $locked['status'];
            if (in_array($status, [TargetPushPolicy::STATUS_PAID, TargetPushPolicy::STATUS_SENDING, TargetPushPolicy::STATUS_COMPLETED, TargetPushPolicy::STATUS_PARTIAL], true)) {
                $this->db->commit();

                return ['ok' => true, 'already_paid' => true, 'id' => $id, 'status' => $status];
            }
            if ($status === TargetPushPolicy::STATUS_PENDING && trim((string) ($locked['redirect_url'] ?? '')) !== '') {
                $this->db->commit();

                return [
                    'ok' => true,
                    'redirect_url' => (string) $locked['redirect_url'],
                    'amount' => TargetPushPolicy::PRICE_KZT,
                    'id' => $id,
                ];
            }
            if ($status === TargetPushPolicy::STATUS_PENDING && trim((string) ($locked['pg_order_id'] ?? '')) !== '') {
                $this->db->commit();

                return $this->initGateway($this->campaigns->find($id) ?: $locked);
            }
            $audience = $this->campaigns->countAudience((string) $locked['category_key'], $userId);
            $block = TargetPushPolicy::purchaseBlock(true, true, $status, $audience);
            if ($block !== null) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => $block, 'id' => $id, 'count' => $audience];
            }
            if ((int) $locked['amount'] !== TargetPushPolicy::PRICE_KZT) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'bad_amount', 'id' => $id];
            }
            $pgOrderId = TargetPushPolicy::PG_PREFIX . bin2hex(random_bytes(8));
            $this->campaigns->savePaymentStart($id, $pgOrderId, is_string($idem) ? $idem : null);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) ($e->errorInfo[1] ?? '') === '1062' || str_contains($e->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'error_code' => 'idempotency_conflict', 'id' => $id];
            }

            return ['ok' => false, 'error_code' => 'generic', 'id' => $id];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'error_code' => 'generic', 'id' => $id];
        }

        $fresh = $this->campaigns->find($id);
        if (!$fresh) {
            return ['ok' => false, 'error_code' => 'not_found', 'id' => $id];
        }

        return $this->initGateway($fresh);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleGatewayResult(
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        int $pgResult,
        bool $canReject,
        ?string $pgCurrency = null
    ): array {
        $order = $this->campaigns->findByPgOrderId($pgOrderId);
        if (!$order) {
            return ['ok' => false, 'gateway' => 'error', 'error' => 'payment_not_found'];
        }

        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById((int) $order['id']);
            if (!$locked) {
                $this->db->rollBack();

                return ['ok' => false, 'gateway' => 'error', 'error' => 'payment_not_found'];
            }
            $decision = TargetPushPolicy::decideCallback(
                (string) $locked['status'],
                (int) $locked['amount'],
                AdPolicyAmount::parse($pgAmount),
                $pgResult === 1,
                $this->isEnabled(),
                $canReject,
                AdPolicyAmount::currencyOk($pgCurrency)
            );
            if (!empty($decision['starts_send'])) {
                $this->db->rollBack();

                return ['ok' => false, 'gateway' => 'error', 'error' => 'starts_send'];
            }
            if ($decision['idempotent']) {
                $this->db->commit();

                return [
                    'ok' => true,
                    'gateway' => 'ok',
                    'status' => (string) $locked['status'],
                    'order_id' => (int) $locked['id'],
                    'recorded' => false,
                    'started' => false,
                ];
            }
            $status = (string) $decision['next_status'];
            $paidAt = $status === TargetPushPolicy::STATUS_PAID ? date('Y-m-d H:i:s') : null;
            $this->campaigns->savePaymentResult(
                (int) $locked['id'],
                $status,
                $pgPaymentId !== '' ? $pgPaymentId : null,
                $decision['failure_reason'],
                $paidAt
            );
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('target_push.persist_failed', 'Сбой записи оплаты Target Push', 'target_push', (int) $order['id']);

            return ['ok' => false, 'gateway' => 'error', 'error' => 'persist_failed', 'order_id' => (int) $order['id']];
        }

        ActivityLogger::info('target_push.callback', 'Результат оплаты Target Push', 'target_push', (int) $order['id'], [
            'status' => $status,
            'started' => false,
        ]);

        $gateway = (string) $decision['gateway'];
        if ($gateway === 'rejected' && !$canReject) {
            $gateway = 'error';
        }

        return [
            'ok' => $gateway === 'ok',
            'gateway' => $gateway,
            'status' => $status,
            'order_id' => (int) $order['id'],
            'recorded' => !empty($decision['record']),
            'started' => false,
            'error' => $decision['failure_reason'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function launch(int $userId, int $id): array
    {
        new Notification();

        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById($id);
            if (!$locked || (int) $locked['user_id'] !== $userId) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'forbidden'];
            }
            $status = (string) $locked['status'];
            if (TargetPushPolicy::consumesSlot($status)) {
                $this->db->commit();

                return ['ok' => true, 'already_started' => true, 'id' => $id, 'status' => $status];
            }
            $category = (string) $locked['category_key'];
            $audience = $this->campaigns->countAudience($category, $userId);
            $day = TargetPushPolicy::sendDay(time());
            $slot = TargetPushPolicy::slotKey($category, $day);
            $block = TargetPushPolicy::launchBlock(
                $this->isEnabled(),
                true,
                $status,
                $audience,
                !$this->campaigns->slotTaken($slot, $id)
            );
            if ($block !== null) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => $block, 'id' => $id, 'count' => $audience];
            }
            if ((int) $locked['amount'] !== TargetPushPolicy::PRICE_KZT) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'bad_amount', 'id' => $id];
            }
            if (!$this->campaigns->markSending($id, $slot, $day, $audience)) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'bad_status', 'id' => $id];
            }
            $inserted = $this->campaigns->insertRecipients($id, $category, $userId);
            if ($inserted < 1 || $inserted > TargetPushPolicy::MAX_RECIPIENTS) {
                throw new RuntimeException($inserted > TargetPushPolicy::MAX_RECIPIENTS ? 'audience_over' : 'audience_empty');
            }
            $this->campaigns->setAudienceCount($id, $inserted);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) ($e->errorInfo[1] ?? '') === '1062' || str_contains($e->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'error_code' => 'frequency', 'id' => $id];
            }

            return ['ok' => false, 'error_code' => 'generic', 'id' => $id];
        } catch (RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'error_code' => $e->getMessage(), 'id' => $id];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'error_code' => 'generic', 'id' => $id];
        }

        ActivityLogger::info('target_push.launched', 'Target Push запущен', 'target_push', $id, [
            'category' => $category,
            'day' => $day,
            'audience' => $inserted,
        ]);
        $written = $this->dispatchCampaign($id, 200);
        $fresh = $this->campaigns->find($id);

        return [
            'ok' => true,
            'id' => $id,
            'status' => (string) ($fresh['status'] ?? TargetPushPolicy::STATUS_SENDING),
            'count' => $inserted,
            'accepted' => $written,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(int $userId, int $id): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        if (!TargetPushPolicy::mayRetry((string) $row['status'])) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }

        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById($id);
            if (!$locked || (string) $locked['status'] !== TargetPushPolicy::STATUS_PARTIAL) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'bad_status'];
            }
            $this->campaigns->requeueFailed($id);
            $this->campaigns->markRetrying($id);
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'error_code' => 'generic'];
        }

        $written = $this->dispatchCampaign($id, 200);

        return ['ok' => true, 'id' => $id, 'accepted' => $written];
    }

    public function dispatchDue(int $limit = 200): int
    {
        $written = 0;
        foreach ($this->campaigns->listDispatchable() as $row) {
            if ((string) $row['status'] === TargetPushPolicy::STATUS_PARTIAL) {
                continue;
            }
            $written += $this->dispatchCampaign((int) $row['id'], $limit);
        }

        return $written;
    }

    public function dispatchCampaign(int $id, int $limit): int
    {
        $row = $this->campaigns->find($id);
        if (!$row || !in_array((string) $row['status'], [TargetPushPolicy::STATUS_SENDING, TargetPushPolicy::STATUS_PARTIAL], true)) {
            return 0;
        }
        $notifications = new Notification();
        $link = ProductHelper::url('/target-push/notice/' . (string) $row['public_id']);
        $message = TargetPushPolicy::compose((string) $row['title'], (string) $row['body']);
        $written = 0;

        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById($id);
            if (!$locked || (string) $locked['status'] !== TargetPushPolicy::STATUS_SENDING) {
                $this->db->commit();

                return 0;
            }
            foreach ($this->campaigns->lockPendingRecipients($id, $limit) as $recipient) {
                $decision = TargetPushPolicy::decideRecipient((string) $recipient['status']);
                if (empty($decision['write'])) {
                    continue;
                }
                $this->db->exec('SAVEPOINT tp_recipient');
                try {
                    $notifications->createFor((int) $recipient['user_id'], $message, $link);
                    $this->campaigns->markRecipient((int) $recipient['id'], TargetPushPolicy::RECIPIENT_ACCEPTED);
                    $this->db->exec('RELEASE SAVEPOINT tp_recipient');
                    $written++;
                } catch (Throwable) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT tp_recipient');
                    $this->campaigns->markRecipient((int) $recipient['id'], TargetPushPolicy::RECIPIENT_FAILED);
                }
            }
            $tallies = $this->campaigns->recipientTallies($id);
            $this->campaigns->saveProgress(
                $id,
                $tallies['accepted'],
                $tallies['failed'],
                TargetPushPolicy::finishStatus($tallies['pending'], $tallies['failed'])
            );
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('target_push.dispatch_failed', 'Сбой записи Target Push', 'target_push', $id);

            return $written;
        }

        return $written;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function notice(string $publicId): ?array
    {
        $row = $this->campaigns->findByPublicId($publicId);
        if (!$row || !TargetPushPolicy::canShowNotice((string) $row['status'])) {
            return null;
        }

        return $row;
    }

    public function unsubscribe(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $this->campaigns->unsubscribe($userId);

        return true;
    }

    public function isUnsubscribed(int $userId): bool
    {
        return $this->campaigns->isUnsubscribed($userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mine(int $userId): array
    {
        return $this->campaigns->listForUser($userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminList(): array
    {
        return $this->campaigns->listForAdmin();
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function initGateway(array $campaign): array
    {
        $id = (int) $campaign['id'];
        if ((int) $campaign['amount'] !== TargetPushPolicy::PRICE_KZT || !$this->isEnabled()) {
            $this->failPending($id, 'disabled');

            return ['ok' => false, 'error_code' => 'disabled', 'id' => $id];
        }
        if (!$this->gateway->isConfigured()) {
            $this->failPending($id, 'gateway_init_failed');

            return ['ok' => false, 'error_code' => 'gateway_not_configured', 'id' => $id];
        }
        $intent = $this->gateway->createPaymentIntent([
            'order_id' => (string) $campaign['pg_order_id'],
            'amount' => TargetPushPolicy::PRICE_KZT,
            'description' => 'Target Push Zakopeyki.kz',
            'param1' => (string) $campaign['public_id'],
        ]);
        if (empty($intent['ok']) || empty($intent['redirect_url'])) {
            $this->failPending($id, 'gateway_init_failed');
            ActivityLogger::warning('target_push.gateway_init_failed', 'Шлюз не открыл оплату Target Push', 'target_push', $id);

            return ['ok' => false, 'error_code' => 'gateway_init_failed', 'id' => $id];
        }
        $this->campaigns->saveRedirect(
            $id,
            (string) $intent['redirect_url'],
            isset($intent['external_id']) ? (string) $intent['external_id'] : null
        );

        return [
            'ok' => true,
            'id' => $id,
            'redirect_url' => (string) $intent['redirect_url'],
            'amount' => TargetPushPolicy::PRICE_KZT,
            'status' => TargetPushPolicy::STATUS_PENDING,
        ];
    }

    private function failPending(int $id, string $reason): void
    {
        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById($id);
            if ($locked && (string) $locked['status'] === TargetPushPolicy::STATUS_PENDING && empty($locked['redirect_url'])) {
                $this->campaigns->savePaymentResult($id, TargetPushPolicy::STATUS_FAILED, null, $reason, null);
            }
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }

    private function normalizeIdempotencyKey(mixed $raw): string|null|false
    {
        if ($raw === null) {
            return null;
        }
        $key = trim((string) $raw);
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return false;
        }

        return $key;
    }
}

/**
 * Сумма и валюта читаются так же, как у баннерной рекламы.
 */
final class AdPolicyAmount
{
    public static function parse(string $raw): ?int
    {
        return \App\Services\Ads\AdPolicy::parseExactTenge($raw);
    }

    public static function currencyOk(?string $currency): bool
    {
        return \App\Services\Ads\AdPolicy::currencyOk($currency);
    }
}
