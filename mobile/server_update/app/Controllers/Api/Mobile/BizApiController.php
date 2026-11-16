<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\BusinessPackageService;
use App\Services\BusinessUpgradeService;
use App\Services\MobileApi\Presenter;

/**
 * Бизнес-аккаунт в приложении: заявка на статус бизнеса (ИП/ТОО) и бизнес-пакет (тарифы, доп. опции, оплата с кошелька).
 * Правила — те же, что у сайта (BusinessController::packageIndex / packagePurchase / upgradeSubmit).
 * Импорт каталога, API-ключи и AI-генераторы остаются инструментами сайта.
 */
final class BizApiController extends BaseMobileController
{
    private const USAGE_LABELS = [
        'catalog' => 'Товаров в каталоге',
        'listings_per_day' => 'Новых объявлений сегодня',
        'ai_infographic' => 'AI-инфографика',
        'ai_copy' => 'AI-описания и SEO',
        'ai_optimize' => 'AI-оптимизация',
        'ai_tryon' => 'Виртуальная примерка',
        'boosts' => 'Бесплатные поднятия',
        'staff' => 'Сотрудники',
    ];

    /** GET /business */
    public function index(): void
    {
        $uid = $this->requireUserId();
        $svc = new BusinessPackageService();
        $user = (new User())->find($uid) ?? [];
        $sub = $svc->activeSubscription($uid);
        $plans = array_values(array_filter($svc->catalog(), static fn (array $p): bool => ($p['slug'] ?? '') === 'business-month'));
        $addons = array_values(array_filter(
            $svc->addons(),
            static fn (array $a): bool => !BusinessPackagePolicy::isAiAddonSlug((string) ($a['slug'] ?? ''))
        ));
        $upgrade = new BusinessUpgradeService();
        $latest = $upgrade->latestForUser($uid);

        $this->ok([
            'is_business' => $svc->isBusinessVerified($user),
            'sales_enabled' => $svc->isSalesEnabled(),
            'wallet_balance' => (new Wallet())->balance($uid),
            'subscription' => $sub ? [
                'name' => (string) ($sub['package_name'] ?? ''),
                'starts_at' => Presenter::date($sub['starts_at'] ?? null),
                'ends_at' => Presenter::date($sub['ends_at'] ?? null),
            ] : null,
            'usage' => array_map(static fn (array $u): array => [
                'label' => self::USAGE_LABELS[$u['key']] ?? (string) $u['key'],
                'used' => (int) $u['used'],
                'cap' => (int) $u['cap'],
            ], $sub ? $svc->usageSnapshot($uid) : []),
            'plans' => array_map(fn (array $p): array => $this->package($p), $plans),
            'addons' => array_map(fn (array $p): array => $this->package($p), $addons),
            'payments' => array_map(static fn (array $p): array => [
                'name' => (string) ($p['package_name'] ?? $p['name'] ?? ''),
                'amount' => (int) ($p['amount'] ?? $p['amount_kzt'] ?? 0),
                'status' => (string) ($p['status'] ?? ''),
                'created_at' => Presenter::date($p['created_at'] ?? null),
            ], $svc->paymentHistory($uid, 10)),
            'upgrade_request' => $latest ? [
                'status' => (string) ($latest['status'] ?? ''),
                'note' => ($latest['review_note'] ?? $latest['note'] ?? '') !== '' ? (string) ($latest['review_note'] ?? $latest['note']) : null,
                'created_at' => Presenter::date($latest['created_at'] ?? null),
            ] : null,
        ]);
    }

    /** POST /business/packages/{id}/buy {idempotency_key} — оплата с кошелька */
    public function buy(string $id): void
    {
        $uid = $this->requireUserId();
        $key = $this->input('idempotency_key');
        $res = (new BusinessPackageService())->purchase($uid, (int) $id, $key !== '' ? mb_substr($key, 0, 64) : null, []);
        if (empty($res['ok'])) {
            $this->fail('business_' . (string) ($res['code'] ?? 'error'), 422, (string) ($res['error'] ?? t('business.err_generic')));
        }
        $this->ok([
            'message' => !empty($res['idempotent']) ? t('business.flash_package_idempotent') : t('business.flash_package_activated'),
        ]);
    }

    /** POST /business/upgrade (multipart: entity_type ip|too, business_name, bin, phone, address, docs[]) */
    public function upgrade(): void
    {
        $uid = $this->requireUserId();
        $res = (new BusinessUpgradeService())->submit($uid, [
            'entity_type' => $this->input('entity_type'),
            'business_name' => $this->input('business_name'),
            'bin' => $this->input('bin'),
            'phone' => $this->input('phone'),
            'address' => $this->input('address'),
        ], $_FILES['docs'] ?? []);
        if (empty($res['ok'])) {
            $this->fail('business_upgrade_failed', 422, (string) ($res['error'] ?? t('business.err_generic')));
        }
        $this->ok(['message' => t('business.flash_upgrade_submitted')], [], 201);
    }

    /** @param array<string, mixed> $p */
    private function package(array $p): array
    {
        $benefits = json_decode((string) ($p['benefits_json'] ?? '[]'), true);

        return [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'description' => ($p['description'] ?? '') !== '' ? (string) $p['description'] : null,
            'price' => (int) ($p['price_kzt'] ?? 0),
            'duration_days' => (int) ($p['duration_days'] ?? 0),
            'benefits' => is_array($benefits) ? array_values(array_map('strval', $benefits)) : [],
        ];
    }
}
