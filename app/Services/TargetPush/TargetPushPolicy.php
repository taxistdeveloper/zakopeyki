<?php

declare(strict_types=1);

namespace App\Services\TargetPush;

use App\Services\Ads\AdPolicy;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Target Push: одна оплаченная отправка в колокольчик сайта.
 *
 * Отдельного push-провайдера, токенов устройств и согласия на рекламу в проекте нет.
 * Канал — таблица notifications. «Принято» означает, что строка уведомления записана,
 * и не означает доставку на устройство.
 * Аудитория — пользователи с товаром этой категории в избранном, кроме автора
 * кампании и тех, кто отписался. Родительская категория включает свои подкатегории,
 * как уже принято для бокового баннера. Токен устройства основанием не является.
 * День частоты — календарный день часового пояса площадки (по умолчанию Asia/Almaty).
 * Слот дня занимается в момент запуска, не в момент оплаты. Отмена до запуска слот не занимает.
 * Оплата не запускает рассылку. Выключение запрещает новые заказы, оплату и новый запуск.
 * Уже начатая отправка дописывается. Повторное включение само ничего не запускает и не списывает.
 * Возврат этим правилом не создаётся — как у остальных платных услуг.
 */
final class TargetPushPolicy
{
    public const PRICE_KZT = 5900;

    public const MAX_RECIPIENTS = 10000;

    public const SETTING_KEY = 'target_push_enabled';

    public const PG_PREFIX = 'zk-tp-';

    public const STATUS_MODERATION = 'pending_moderation';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_SENDING = 'sending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const RECIPIENT_PENDING = 'pending';
    public const RECIPIENT_ACCEPTED = 'accepted';
    public const RECIPIENT_FAILED = 'failed';

    public const IN_PROGRESS_SURVIVES_DISABLE = true;

    public const AUTO_LAUNCH_ON_ENABLE = false;

    public const PAYMENT_STARTS_SEND = false;

    public const REFUNDS = false;

    /** @var list<string> */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'three_lifts_enabled',
        'highlight_enabled',
        'top_fixed_enabled',
        'top_fixed_xl_enabled',
        'vip_enabled',
        'ads_hero_enabled',
        'ads_category_enabled',
        'ads_sidebar_enabled',
        'ads_exclusive_enabled',
        'mass_push_enabled',
        'stub_mode',
    ];

    public static function serverQuote(array $input = []): int
    {
        unset($input['amount'], $input['price'], $input['total'], $input['sum'], $input['enabled']);

        return self::PRICE_KZT;
    }

    public static function isPgOrderId(string $pgOrderId): bool
    {
        return str_starts_with($pgOrderId, self::PG_PREFIX);
    }

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

    public static function resolveEnabled(mixed $stored, bool $readFailed = false): bool
    {
        if ($readFailed || $stored === null) {
            return false;
        }
        if (is_bool($stored)) {
            return $stored;
        }
        if (is_int($stored)) {
            return $stored === 1;
        }
        if (is_string($stored)) {
            return in_array(strtolower(trim($stored)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    public static function appTimezone(): string
    {
        $tz = $GLOBALS['appConfig']['timezone'] ?? 'Asia/Almaty';

        return is_string($tz) && $tz !== '' ? $tz : 'Asia/Almaty';
    }

    public static function sendDay(int $timestamp, ?string $timezone = null): string
    {
        $zone = new DateTimeZone($timezone ?? self::appTimezone());

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d');
    }

    public static function slotKey(string $category, string $day): string
    {
        return 'tp-' . substr(sha1($category . '|' . $day), 0, 40);
    }

    public static function isCategoryKey(string $key): bool
    {
        return AdPolicy::isCategoryKey($key);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    public static function audienceParams(string $category): array
    {
        if (str_contains($category, ' / ')) {
            return [$category, 0, ''];
        }

        return [$category, 1, $category];
    }

    public static function productInCategory(string $productCategory, string $campaignKey): bool
    {
        $campaignKey = trim($campaignKey);

        return $campaignKey !== '' && AdPolicy::sidebarMatches($campaignKey, $productCategory);
    }

    public static function recipientEligible(bool $inCategory, bool $unsubscribed, bool $isAdvertiser): bool
    {
        return $inCategory && !$unsubscribed && !$isAdvertiser;
    }

    public static function titleError(string $title): ?string
    {
        $len = mb_strlen(trim($title));
        if ($len < 2 || $len > 80) {
            return 'bad_title';
        }

        return null;
    }

    public static function bodyError(string $body): ?string
    {
        $len = mb_strlen(trim($body));
        if ($len < 2 || $len > 320) {
            return 'bad_body';
        }

        return null;
    }

    public static function compose(string $title, string $body): string
    {
        return trim($title) . ': ' . trim($body);
    }

    public static function messageError(string $title, string $body): ?string
    {
        $titleError = self::titleError($title);
        if ($titleError !== null) {
            return $titleError;
        }
        $bodyError = self::bodyError($body);
        if ($bodyError !== null) {
            return $bodyError;
        }
        if (mb_strlen(self::compose($title, $body)) > 500) {
            return 'bad_message';
        }

        return null;
    }

    public static function targetUrlError(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (strlen($url) > 255 || preg_match('/[\s<>"\']/', $url) === 1) {
            return 'bad_url';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'bad_url';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'bad_url';
        }

        return null;
    }

    public static function consumesSlot(string $status): bool
    {
        return in_array($status, [self::STATUS_SENDING, self::STATUS_COMPLETED, self::STATUS_PARTIAL], true);
    }

    public static function mayCancel(string $status): bool
    {
        return in_array($status, [
            self::STATUS_MODERATION,
            self::STATUS_REJECTED,
            self::STATUS_APPROVED,
            self::STATUS_PENDING,
            self::STATUS_PAID,
            self::STATUS_FAILED,
        ], true);
    }

    public static function mayEdit(string $status): bool
    {
        return in_array($status, [self::STATUS_MODERATION, self::STATUS_REJECTED], true);
    }

    public static function mayRetry(string $status): bool
    {
        return $status === self::STATUS_PARTIAL;
    }

    public static function canShowNotice(string $status): bool
    {
        return self::consumesSlot($status);
    }

    /**
     * @return array{write: bool, idempotent: bool}
     */
    public static function decideRecipient(string $status): array
    {
        if ($status === self::RECIPIENT_ACCEPTED) {
            return ['write' => false, 'idempotent' => true];
        }
        if ($status === self::RECIPIENT_PENDING) {
            return ['write' => true, 'idempotent' => false];
        }

        return ['write' => false, 'idempotent' => true];
    }

    public static function finishStatus(int $pending, int $failed): string
    {
        if ($pending > 0) {
            return self::STATUS_SENDING;
        }
        if ($failed > 0) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_COMPLETED;
    }

    public static function createBlock(bool $enabled, bool $categoryOk, ?string $messageError, ?string $urlError, int $audience): ?string
    {
        if (!$enabled) {
            return 'disabled';
        }
        if (!$categoryOk) {
            return 'bad_category';
        }
        if ($messageError !== null) {
            return $messageError;
        }
        if ($urlError !== null) {
            return $urlError;
        }
        if ($audience > self::MAX_RECIPIENTS) {
            return 'audience_over';
        }

        return null;
    }

    public static function purchaseBlock(bool $enabled, bool $owner, string $status, int $audience): ?string
    {
        if (!$owner) {
            return 'forbidden';
        }
        if (!$enabled) {
            return 'disabled';
        }
        if ($status !== self::STATUS_APPROVED) {
            return 'bad_status';
        }
        if ($audience < 1) {
            return 'audience_empty';
        }
        if ($audience > self::MAX_RECIPIENTS) {
            return 'audience_over';
        }

        return null;
    }

    public static function launchBlock(bool $enabled, bool $owner, string $status, int $audience, bool $slotFree): ?string
    {
        if (!$owner) {
            return 'forbidden';
        }
        if (!$enabled) {
            return 'disabled';
        }
        if ($status !== self::STATUS_PAID) {
            return 'bad_status';
        }
        if ($audience < 1) {
            return 'audience_empty';
        }
        if ($audience > self::MAX_RECIPIENTS) {
            return 'audience_over';
        }
        if (!$slotFree) {
            return 'frequency';
        }

        return null;
    }

    /**
     * Оплата переводит кампанию в «оплачено» и никогда не начинает рассылку.
     *
     * @return array{record: bool, next_status: string, gateway: string, idempotent: bool, failure_reason: ?string, starts_send: bool}
     */
    public static function decideCallback(
        string $status,
        int $storedAmount,
        ?int $paidAmount,
        bool $gatewaySuccess,
        bool $featureEnabled,
        bool $canReject,
        bool $currencyOk = true
    ): array {
        $decision = [
            'record' => false,
            'next_status' => $status,
            'gateway' => 'ok',
            'idempotent' => false,
            'failure_reason' => null,
            'starts_send' => false,
        ];

        if (in_array($status, [self::STATUS_PAID, self::STATUS_SENDING, self::STATUS_COMPLETED, self::STATUS_PARTIAL], true)) {
            $decision['idempotent'] = true;

            return $decision;
        }

        if (!$gatewaySuccess) {
            if ($status === self::STATUS_PENDING) {
                $decision['next_status'] = self::STATUS_FAILED;
                $decision['failure_reason'] = 'gateway_failed';
            }

            return $decision;
        }

        if (in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_MODERATION], true)) {
            $decision['gateway'] = $canReject ? 'rejected' : 'error';
            $decision['failure_reason'] = 'terminal';

            return $decision;
        }

        $amountOk = $currencyOk
            && $storedAmount === self::PRICE_KZT
            && $paidAmount === self::PRICE_KZT;
        if (!$amountOk) {
            $decision['gateway'] = $canReject ? 'rejected' : 'error';
            $decision['failure_reason'] = 'amount_mismatch';

            return $decision;
        }

        if (!$featureEnabled) {
            if ($status === self::STATUS_PENDING && $canReject) {
                $decision['next_status'] = self::STATUS_FAILED;
                $decision['gateway'] = 'rejected';
                $decision['failure_reason'] = 'disabled';

                return $decision;
            }
            if ($status === self::STATUS_PENDING) {
                $decision['record'] = true;
                $decision['next_status'] = self::STATUS_PAID;
                $decision['failure_reason'] = 'disabled';

                return $decision;
            }
            $decision['failure_reason'] = 'disabled';

            return $decision;
        }

        if ($status === self::STATUS_PENDING) {
            $decision['record'] = true;
            $decision['next_status'] = self::STATUS_PAID;

            return $decision;
        }

        $decision['gateway'] = 'error';
        $decision['failure_reason'] = 'invalid_status';

        return $decision;
    }

    /**
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'target_push.feature_on' : 'target_push.feature_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => self::SETTING_KEY,
                'before' => $before,
                'after' => $after,
                'keeps_history' => true,
                'in_progress_survives' => self::IN_PROGRESS_SURVIVES_DISABLE,
                'auto_launch_on_enable' => self::AUTO_LAUNCH_ON_ENABLE,
                'refunds' => self::REFUNDS,
                'other_settings' => [],
            ],
        ];
    }
}
