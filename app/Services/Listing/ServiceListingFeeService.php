<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Core\Auth;
use App\Helpers\ActivityLogger;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Throwable;

/**
 * Списание платы за публикацию service и флаг новых публикаций.
 */
final class ServiceListingFeeService
{
    private Wallet $wallet;
    private Setting $settings;

    public function __construct(?Wallet $wallet = null, ?Setting $settings = null)
    {
        $this->wallet = $wallet ?? new Wallet();
        $this->settings = $settings ?? new Setting();
    }

    public function feeForType(string $type, ?int $clientAmount = null): int
    {
        return ServiceListingFeePolicy::feeKztForType($type, $clientAmount);
    }

    public function userIsStaff(int $userId): bool
    {
        $user = (new User())->find($userId);
        $role = (string) ($user['role'] ?? '');

        return $role === 'admin' || $role === 'manager';
    }

    public function isPublishEnabled(): bool
    {
        try {
            $this->settings->forget(ServiceListingFeePolicy::SETTING_KEY);

            return ServiceListingFeePolicy::resolveEnabled(
                $this->settings->getBool(ServiceListingFeePolicy::SETTING_KEY, true)
            );
        } catch (Throwable) {
            return ServiceListingFeePolicy::resolveEnabled(null, true);
        }
    }

    public function setPublishEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!ServiceListingFeePolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'service_listing.feature_toggle_denied',
                'Публикация «Услуги» не переключена: нет права администратора',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return false;
        }

        try {
            $before = $this->isPublishEnabled();
            $this->settings->setBool(ServiceListingFeePolicy::SETTING_KEY, $enabled);
            $audit = ServiceListingFeePolicy::toggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Новые публикации «Услуги» включены' : 'Новые публикации «Услуги» выключены',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            ActivityLogger::warning(
                'service_listing.feature_toggle_failed',
                'Не удалось переключить публикации «Услуги»',
                'settings',
                null
            );

            return false;
        }
    }

    /**
     * Серверный gate новых публикаций service. Другие типы — без ограничений этого флага.
     *
     * @return array{ok: bool, error?: string, code?: string}
     */
    public function assertCanPublishNew(string $type): array
    {
        $gate = ServiceListingFeePolicy::assertNewPublishAllowed($type, $this->isPublishEnabled());
        if (!empty($gate['ok'])) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'code' => $gate['code'] ?? 'publish_disabled',
            'error' => t('flash.service_publish_disabled'),
        ];
    }

    /**
     * @return array{ok: bool, fee: int, error?: string}
     */
    public function assertAffordable(int $userId, string $type, bool $isStaff = false): array
    {
        $fee = $this->feeForType($type);
        if ($fee <= 0 || $isStaff) {
            return ['ok' => true, 'fee' => 0];
        }

        if ($this->wallet->balance($userId) < $fee) {
            return [
                'ok' => false,
                'fee' => $fee,
                'error' => t('flash.service_fee_need', [
                    'amount' => Wallet::formatMoney($fee),
                ]),
            ];
        }

        return ['ok' => true, 'fee' => $fee];
    }

    /**
     * Списать fee после создания объявления. Идемпотентно по product_id.
     * Staff не платит; сумма только серверная.
     *
     * @return array{ok: bool, fee: int, error?: string, idempotent?: bool, balance?: int}
     */
    public function chargeForPublishedProduct(
        int $userId,
        string $type,
        int $productId,
        bool $isStaff = false
    ): array {
        $fee = $this->feeForType($type);
        if ($fee <= 0 || $isStaff) {
            return ['ok' => true, 'fee' => 0];
        }

        if ($productId <= 0) {
            return ['ok' => false, 'fee' => $fee, 'error' => t('wallet.op_failed')];
        }

        $pay = $this->wallet->chargeListingFee($userId, $fee, $productId);
        if (empty($pay['ok'])) {
            return [
                'ok' => false,
                'fee' => $fee,
                'error' => $pay['error'] ?? t('flash.service_fee_need', [
                    'amount' => Wallet::formatMoney($fee),
                ]),
            ];
        }

        return [
            'ok' => true,
            'fee' => $fee,
            'balance' => $pay['balance'] ?? null,
            'idempotent' => !empty($pay['idempotent']),
        ];
    }
}
