<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Models\AdCampaign;
use App\Models\Setting;
use App\Services\Payment\FreedomPayGateway;
use App\Services\Payment\PaymentGatewayInterface;
use PDO;
use PDOException;
use Throwable;

/**
 * Кампании четырёх форматов на одном контуре: модерация, оплата, показ, учёт.
 * Уже активная кампания продолжает идти после выключения формата.
 * Новые заказы и активация при выключенном формате не проходят.
 */
final class AdService
{
    private AdCampaign $campaigns;
    private Setting $settings;
    private PaymentGatewayInterface $gateway;
    private PDO $db;

    public function __construct(
        ?AdCampaign $campaigns = null,
        ?Setting $settings = null,
        ?PaymentGatewayInterface $gateway = null
    ) {
        $this->campaigns = $campaigns ?? new AdCampaign();
        $this->settings = $settings ?? new Setting();
        $this->gateway = $gateway ?? new FreedomPayGateway();
        $this->db = Database::connect();
    }

    public function isEnabled(string $format): bool
    {
        $key = AdPolicy::settingKey($format);
        if ($key === null) {
            return false;
        }
        try {
            $this->settings->forget($key);

            return AdPolicy::resolveEnabled($this->settings->getBool($key, false));
        } catch (Throwable) {
            return AdPolicy::resolveEnabled(null, true);
        }
    }

    /**
     * @return array<string, bool>
     */
    public function flags(): array
    {
        $out = [];
        foreach (array_keys(AdPolicy::SETTING_KEYS) as $format) {
            $out[$format] = $this->isEnabled($format);
        }

        return $out;
    }

    public function setEnabled(string $format, bool $enabled): bool
    {
        if (!AdPolicy::isFormat($format)) {
            return false;
        }
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!AdPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning('ads.feature_toggle_denied', 'Реклама не переключена: нет права администратора', 'settings', null, [
                'format' => $format,
                'actor_id' => $actorId,
            ]);

            return false;
        }

        try {
            $before = $this->isEnabled($format);
            $this->settings->setBool((string) AdPolicy::settingKey($format), $enabled);
            $audit = AdPolicy::toggleAudit($format, $actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Рекламный формат включён' : 'Рекламный формат выключен',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            ActivityLogger::warning('ads.feature_toggle_failed', 'Не удалось переключить рекламный формат', 'settings', null, [
                'format' => $format,
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input, ?array $file): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $format = trim((string) ($input['format'] ?? ''));
        if (!AdPolicy::isFormat($format)) {
            return ['ok' => false, 'error_code' => 'bad_format'];
        }
        if (!$this->isEnabled($format)) {
            return ['ok' => false, 'error_code' => 'disabled'];
        }
        $units = filter_var($input['units'] ?? null, FILTER_VALIDATE_INT);
        if ($units === false) {
            return ['ok' => false, 'error_code' => 'bad_units'];
        }
        $quote = AdPolicy::serverQuote($format, (int) $units, $input);
        if ($quote === null) {
            return ['ok' => false, 'error_code' => 'bad_units'];
        }
        $category = AdPolicy::normalizeCategory($format, isset($input['category']) ? (string) $input['category'] : null);
        if (!$category['ok']) {
            return ['ok' => false, 'error_code' => 'bad_category'];
        }
        $title = trim((string) ($input['title'] ?? ''));
        if (AdPolicy::titleError($title) !== null) {
            return ['ok' => false, 'error_code' => 'bad_title'];
        }
        $url = trim((string) ($input['target_url'] ?? ''));
        if (AdPolicy::targetUrlError($url) !== null) {
            return ['ok' => false, 'error_code' => 'bad_url'];
        }
        $stored = $this->storeImage($file);
        if (isset($stored['error'])) {
            return ['ok' => false, 'error_code' => (string) $stored['error']];
        }

        $id = $this->campaigns->insert([
            'public_id' => bin2hex(random_bytes(16)),
            'user_id' => $userId,
            'format' => $format,
            'category_key' => $category['key'],
            'title' => $title,
            'target_url' => $url,
            'image_file' => $stored['name'],
            'units' => (int) $units,
            'amount' => $quote['amount'],
            'impressions_paid' => $quote['impressions'],
            'status' => AdPolicy::STATUS_MODERATION,
        ]);
        ActivityLogger::info('ads.created', 'Кампания отправлена на модерацию', 'ad_campaign', $id, [
            'format' => $format,
            'amount' => $quote['amount'],
            'units' => (int) $units,
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'amount' => $quote['amount'],
            'impressions' => $quote['impressions'],
            'status' => AdPolicy::STATUS_MODERATION,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function resubmit(int $userId, int $id, array $input, ?array $file): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        if ((string) $row['status'] !== AdPolicy::STATUS_REJECTED) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        $format = (string) $row['format'];
        if (!$this->isEnabled($format)) {
            return ['ok' => false, 'error_code' => 'disabled'];
        }
        $title = trim((string) ($input['title'] ?? ''));
        if (AdPolicy::titleError($title) !== null) {
            return ['ok' => false, 'error_code' => 'bad_title'];
        }
        $url = trim((string) ($input['target_url'] ?? ''));
        if (AdPolicy::targetUrlError($url) !== null) {
            return ['ok' => false, 'error_code' => 'bad_url'];
        }
        $image = null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->storeImage($file);
            if (isset($stored['error'])) {
                return ['ok' => false, 'error_code' => (string) $stored['error']];
            }
            $image = $stored['name'];
        }
        $this->campaigns->saveCreative($id, $title, $url, $image);

        return ['ok' => true, 'id' => $id, 'status' => AdPolicy::STATUS_MODERATION];
    }

    /**
     * @return array<string, mixed>
     */
    public function moderate(int $id, bool $approve, string $reason = ''): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        if (!AdPolicy::canToggle($role)) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $row = $this->campaigns->find($id);
        if (!$row || (string) $row['status'] !== AdPolicy::STATUS_MODERATION) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        $reason = trim($reason);
        if (!$approve && ($reason === '' || mb_strlen($reason) > 255)) {
            return ['ok' => false, 'error_code' => 'bad_reason'];
        }
        $status = $approve ? AdPolicy::STATUS_APPROVED : AdPolicy::STATUS_REJECTED;
        $this->campaigns->saveModeration($id, $status, $approve ? null : $reason);
        ActivityLogger::info($approve ? 'ads.approved' : 'ads.rejected', 'Модерация рекламы', 'ad_campaign', $id, [
            'status' => $status,
        ]);

        return ['ok' => true, 'status' => $status];
    }

    /**
     * @return array<string, mixed>
     */
    public function beginPayment(int $userId, int $id, array $input = []): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $format = (string) $row['format'];
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
            if ($status === AdPolicy::STATUS_PENDING && trim((string) ($locked['redirect_url'] ?? '')) !== '') {
                $this->db->commit();

                return [
                    'ok' => true,
                    'redirect_url' => (string) $locked['redirect_url'],
                    'amount' => (int) $locked['amount'],
                    'id' => $id,
                ];
            }
            if ($status === AdPolicy::STATUS_PENDING && trim((string) ($locked['pg_order_id'] ?? '')) !== '') {
                $this->db->commit();

                return $this->initGateway($locked);
            }
            if ($status === AdPolicy::STATUS_ACTIVE || $status === AdPolicy::STATUS_PAUSED) {
                $this->db->commit();

                return ['ok' => true, 'already_applied' => true, 'id' => $id, 'status' => $status];
            }
            $slotFree = $this->slotFree($locked, $id);
            $block = AdPolicy::purchaseBlock(
                $this->isEnabled($format),
                true,
                $status === AdPolicy::STATUS_APPROVED,
                $slotFree
            );
            if ($block !== null) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => $block, 'id' => $id];
            }
            $quote = AdPolicy::quote($format, (int) $locked['units']);
            if ($quote === null || $quote['amount'] !== (int) $locked['amount']) {
                $this->db->rollBack();

                return ['ok' => false, 'error_code' => 'bad_units', 'id' => $id];
            }
            $pgOrderId = AdPolicy::PG_PREFIX . bin2hex(random_bytes(8));
            $openKey = $format === AdPolicy::FORMAT_EXCLUSIVE
                ? AdPolicy::exclusiveOpenKey((string) $locked['category_key'])
                : null;
            $this->campaigns->savePaymentStart($id, $pgOrderId, $openKey, is_string($idem) ? $idem : null);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $sqlState = (string) ($e->errorInfo[1] ?? '');
            if ($sqlState === '1062' || str_contains($e->getMessage(), 'Duplicate')) {
                $message = $e->getMessage();
                $code = str_contains($message, 'uq_ad_idem') ? 'idempotency_conflict' : 'slot_taken';

                return ['ok' => false, 'error_code' => $code, 'id' => $id];
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
            $format = (string) $locked['format'];
            $slotFree = $this->slotFree($locked, (int) $locked['id']);
            $decision = AdPolicy::decideCallback(
                (string) $locked['status'],
                $format,
                (int) $locked['amount'],
                (int) $locked['units'],
                AdPolicy::parseExactTenge($pgAmount),
                $pgResult === 1,
                $this->isEnabled($format),
                $slotFree,
                $canReject,
                AdPolicy::currencyOk($pgCurrency)
            );

            if ($decision['idempotent']) {
                $this->db->commit();

                return [
                    'ok' => true,
                    'gateway' => 'ok',
                    'status' => (string) $locked['status'],
                    'order_id' => (int) $locked['id'],
                    'applied' => false,
                ];
            }

            $status = (string) $decision['next_status'];
            $reason = $decision['failure_reason'];
            $startsAt = null;
            $endsAt = null;
            $applied = false;
            if ($decision['apply']) {
                $now = time();
                $startsAt = AdPolicy::sqlDateTime($now);
                if ($format === AdPolicy::FORMAT_EXCLUSIVE) {
                    $period = AdPolicy::exclusivePeriod($now, (int) $locked['units']);
                    if ($period === null) {
                        $status = AdPolicy::STATUS_PAID;
                        $reason = 'bad_units';
                        $startsAt = null;
                    } else {
                        $endsAt = AdPolicy::sqlDateTime($period['ends_at']);
                        $applied = true;
                    }
                } else {
                    $applied = true;
                }
            }
            $paidAt = in_array($status, [AdPolicy::STATUS_PAID, AdPolicy::STATUS_ACTIVE], true)
                ? date('Y-m-d H:i:s')
                : null;
            $openKey = AdPolicy::keepsOpenLock($status) && $format === AdPolicy::FORMAT_EXCLUSIVE
                ? AdPolicy::exclusiveOpenKey((string) $locked['category_key'])
                : null;
            $this->campaigns->saveState(
                (int) $locked['id'],
                $status,
                $openKey,
                $pgPaymentId !== '' ? $pgPaymentId : null,
                $reason,
                $paidAt,
                $startsAt,
                $endsAt
            );
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning('ads.persist_failed', 'Сбой записи результата рекламы', 'ad_campaign', (int) $order['id']);

            return ['ok' => false, 'gateway' => 'error', 'error' => 'persist_failed', 'order_id' => (int) $order['id']];
        }

        ActivityLogger::info($applied ? 'ads.activated' : 'ads.callback', 'Результат оплаты рекламы', 'ad_campaign', (int) $order['id'], [
            'status' => $status,
            'applied' => $applied,
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
            'applied' => $applied,
            'error' => $reason,
        ];
    }

    /**
     * @return array{ok: bool, applied: int, error_code?: string}
     */
    public function recoverUnapplied(string $format): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        if (!AdPolicy::canToggle($role) || !AdPolicy::isFormat($format)) {
            return ['ok' => false, 'applied' => 0, 'error_code' => 'forbidden'];
        }
        if (!$this->isEnabled($format)) {
            return ['ok' => false, 'applied' => 0, 'error_code' => 'disabled'];
        }
        $applied = 0;
        foreach ($this->campaigns->findUnapplied($format) as $row) {
            $quote = AdPolicy::quote($format, (int) $row['units']);
            if ($quote === null) {
                continue;
            }
            $result = $this->handleGatewayResult(
                (string) $row['pg_order_id'],
                (string) ($row['pg_payment_id'] ?? ''),
                (string) $quote['amount'],
                1,
                false,
                'KZT'
            );
            if (!empty($result['applied'])) {
                $applied++;
            }
        }
        ActivityLogger::info('ads.recover', 'Восстановление оплаченной рекламы', 'ad_campaign', null, [
            'format' => $format,
            'applied' => $applied,
        ]);

        return ['ok' => true, 'applied' => $applied];
    }

    /**
     * @return array<string, mixed>
     */
    public function setRunning(int $userId, int $id, bool $pause): array
    {
        $row = $this->campaigns->find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error_code' => 'forbidden'];
        }
        $status = (string) $row['status'];
        $format = (string) $row['format'];
        if ($pause) {
            if ($status !== AdPolicy::STATUS_ACTIVE) {
                return ['ok' => false, 'error_code' => 'bad_status'];
            }
            $open = $format === AdPolicy::FORMAT_EXCLUSIVE ? AdPolicy::exclusiveOpenKey((string) $row['category_key']) : null;
            $this->campaigns->saveRunStatus($id, AdPolicy::STATUS_PAUSED, $open);

            return ['ok' => true, 'status' => AdPolicy::STATUS_PAUSED];
        }
        if ($status !== AdPolicy::STATUS_PAUSED) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        if (!$this->isEnabled($format)) {
            return ['ok' => false, 'error_code' => 'disabled'];
        }
        $ends = isset($row['ends_at']) ? strtotime((string) $row['ends_at']) : null;
        $ends = $ends === false ? null : $ends;
        if (!AdPolicy::mayIssue(
            AdPolicy::STATUS_ACTIVE,
            (int) $row['impressions_used'],
            (int) $row['impressions_paid'],
            $ends,
            time(),
            true
        )) {
            return ['ok' => false, 'error_code' => 'bad_status'];
        }
        $open = $format === AdPolicy::FORMAT_EXCLUSIVE ? AdPolicy::exclusiveOpenKey((string) $row['category_key']) : null;
        $this->campaigns->saveRunStatus($id, AdPolicy::STATUS_ACTIVE, $open);

        return ['ok' => true, 'status' => AdPolicy::STATUS_ACTIVE];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function heroSlides(): array
    {
        return $this->issue('hero', static fn (array $row): bool => true, AdPolicy::MAX_HERO_SLIDES);
    }

    /**
     * @return array{variant: string, ads: list<array<string, mixed>>}|null
     */
    public function categoryPlacement(string $pageKey): ?array
    {
        $pageKey = trim($pageKey);
        if ($pageKey === '') {
            return null;
        }
        $exclusive = $this->issue(
            AdPolicy::FORMAT_EXCLUSIVE,
            static fn (array $row): bool => AdPolicy::categoryPageMatches((string) ($row['category_key'] ?? ''), $pageKey),
            1
        );
        if ($exclusive !== []) {
            return ['variant' => 'exclusive', 'ads' => $exclusive];
        }
        $banner = $this->issue(
            AdPolicy::FORMAT_CATEGORY,
            static fn (array $row): bool => AdPolicy::categoryPageMatches((string) ($row['category_key'] ?? ''), $pageKey),
            1
        );
        if ($banner === []) {
            return null;
        }

        return ['variant' => 'banner', 'ads' => $banner];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sidebarCreative(?string $productCategory): ?array
    {
        $rows = $this->issue(
            AdPolicy::FORMAT_SIDEBAR,
            static fn (array $row): bool => AdPolicy::sidebarMatches(
                isset($row['category_key']) ? (string) $row['category_key'] : null,
                $productCategory
            ),
            1
        );

        return $rows[0] ?? null;
    }

    /**
     * @return array{ok: bool, counted: bool, error_code?: string}
     */
    public function countImpression(string $token): array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return ['ok' => false, 'counted' => false, 'error_code' => 'not_found'];
        }
        try {
            $this->db->beginTransaction();
            $impression = $this->campaigns->lockImpression($token);
            if (!$impression) {
                $this->db->rollBack();

                return ['ok' => false, 'counted' => false, 'error_code' => 'not_found'];
            }
            $campaign = $this->campaigns->lockById((int) $impression['campaign_id']);
            if (!$campaign) {
                $this->db->rollBack();

                return ['ok' => false, 'counted' => false, 'error_code' => 'not_found'];
            }
            $ends = isset($campaign['ends_at']) && $campaign['ends_at'] !== null
                ? strtotime((string) $campaign['ends_at'])
                : null;
            if ($ends === false) {
                $ends = null;
            }
            $decision = AdPolicy::decideCount(
                (string) $impression['status'],
                (string) $campaign['status'],
                (int) $campaign['impressions_used'],
                (int) $campaign['impressions_paid'],
                $ends,
                time()
            );
            if ($decision['idempotent']) {
                $this->db->commit();

                return ['ok' => true, 'counted' => false];
            }
            if ($decision['count']) {
                $open = (string) $decision['next_status'] === AdPolicy::STATUS_COMPLETED
                    ? null
                    : ($campaign['format'] === AdPolicy::FORMAT_EXCLUSIVE
                        ? AdPolicy::exclusiveOpenKey((string) $campaign['category_key'])
                        : null);
                $written = $this->campaigns->applyCount((int) $campaign['id'], (string) $decision['next_status'], $open);
                if (!$written) {
                    $this->campaigns->markImpression((int) $impression['id'], AdPolicy::IMPRESSION_SKIPPED, false);
                    $this->db->commit();

                    return ['ok' => true, 'counted' => false];
                }
                $this->campaigns->markImpression((int) $impression['id'], AdPolicy::IMPRESSION_COUNTED, true);
                $this->db->commit();

                return ['ok' => true, 'counted' => true];
            }
            $this->campaigns->markImpression((int) $impression['id'], (string) $decision['next_impression'], false);
            if ((string) $decision['next_status'] !== (string) $campaign['status']) {
                $this->campaigns->saveRunStatus((int) $campaign['id'], (string) $decision['next_status'], null);
            }
            $this->db->commit();

            return ['ok' => true, 'counted' => false];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'counted' => false, 'error_code' => 'generic'];
        }
    }

    /**
     * @return array{url: string}|null
     */
    public function clickTarget(string $token): ?array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $impression = $this->campaigns->findImpression($token);
        if (!$impression) {
            return null;
        }
        $campaign = $this->campaigns->find((int) $impression['campaign_id']);
        if (!$campaign) {
            return null;
        }
        $url = (string) $campaign['target_url'];
        if (AdPolicy::targetUrlError($url) !== null) {
            return null;
        }
        if ((string) $campaign['status'] !== AdPolicy::STATUS_ACTIVE) {
            return null;
        }
        try {
            if ($this->campaigns->markClicked($token)) {
                $this->campaigns->addClick((int) $campaign['id']);
            }
        } catch (Throwable) {
            // переход не зависит от записи клика
        }

        return ['url' => $url];
    }

    public function expireDue(): int
    {
        try {
            return $this->campaigns->expireDue();
        } catch (Throwable) {
            return 0;
        }
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
    public function pendingModeration(): array
    {
        return $this->campaigns->listPendingModeration();
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function initGateway(array $campaign): array
    {
        $id = (int) $campaign['id'];
        $format = (string) $campaign['format'];
        $quote = AdPolicy::quote($format, (int) $campaign['units']);
        if ($quote === null || $quote['amount'] !== (int) $campaign['amount'] || !$this->isEnabled($format)) {
            $this->failPending($id, 'disabled');

            return ['ok' => false, 'error_code' => 'disabled', 'id' => $id];
        }
        if (!$this->gateway->isConfigured()) {
            $this->failPending($id, 'gateway_init_failed');

            return ['ok' => false, 'error_code' => 'gateway_not_configured', 'id' => $id];
        }
        $intent = $this->gateway->createPaymentIntent([
            'order_id' => (string) $campaign['pg_order_id'],
            'amount' => $quote['amount'],
            'description' => 'Реклама Zakopeyki.kz',
            'param1' => (string) $campaign['public_id'],
        ]);
        if (empty($intent['ok']) || empty($intent['redirect_url'])) {
            $this->failPending($id, 'gateway_init_failed');
            ActivityLogger::warning('ads.gateway_init_failed', 'Шлюз не открыл оплату рекламы', 'ad_campaign', $id);

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
            'amount' => $quote['amount'],
            'status' => AdPolicy::STATUS_PENDING,
        ];
    }

    private function failPending(int $id, string $reason): void
    {
        try {
            $this->db->beginTransaction();
            $locked = $this->campaigns->lockById($id);
            if ($locked && (string) $locked['status'] === AdPolicy::STATUS_PENDING && empty($locked['redirect_url'])) {
                $this->campaigns->saveState($id, AdPolicy::STATUS_FAILED, null, null, $reason, null, null, null);
            }
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function slotFree(array $row, int $id): bool
    {
        if ((string) ($row['format'] ?? '') !== AdPolicy::FORMAT_EXCLUSIVE) {
            return true;
        }
        $key = trim((string) ($row['category_key'] ?? ''));
        if ($key === '') {
            return false;
        }

        return !$this->campaigns->holdsExclusive($key, $id);
    }

    /**
     * @param callable(array<string, mixed>): bool $match
     * @return list<array<string, mixed>>
     */
    private function issue(string $format, callable $match, int $limit): array
    {
        if (!AdPolicy::isFormat($format)) {
            return [];
        }
        try {
            $this->expireDue();
            $picked = [];
            foreach ($this->campaigns->candidates($format, 20) as $row) {
                if (!$match($row)) {
                    continue;
                }
                $ends = isset($row['ends_at']) && $row['ends_at'] !== null ? strtotime((string) $row['ends_at']) : null;
                if ($ends === false) {
                    $ends = null;
                }
                if (!AdPolicy::mayIssue(
                    (string) $row['status'],
                    (int) $row['impressions_used'],
                    (int) $row['impressions_paid'],
                    $ends,
                    time(),
                    true
                )) {
                    continue;
                }
                $token = bin2hex(random_bytes(16));
                $this->campaigns->issueImpression((int) $row['id'], $token);
                $picked[] = $this->present($row, $token);
                if (count($picked) >= $limit) {
                    break;
                }
            }

            return $picked;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, string $token): array
    {
        return [
            'token' => $token,
            'title' => (string) $row['title'],
            'image' => ProductHelper::url('public/uploads/ads/' . basename((string) $row['image_file'])),
            'href' => ProductHelper::url('/ads/go/' . $token),
            'format' => (string) $row['format'],
        ];
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{name: string}|array{error: string}
     */
    private function storeImage(?array $file): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'bad_image'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'bad_image'];
        }
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : null;
        $width = is_array($info) ? (int) ($info[0] ?? 0) : null;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : null;
        if (AdPolicy::imageError($size, $mime, $width, $height) !== null) {
            return ['error' => 'bad_image'];
        }
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = dirname(__DIR__, 3) . '/public/uploads/ads';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['error' => 'bad_image'];
        }
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            return ['error' => 'bad_image'];
        }

        return ['name' => $name];
    }

    /** @return string|null|false */
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
