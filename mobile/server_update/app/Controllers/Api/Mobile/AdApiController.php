<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ProductHelper;
use App\Services\Ads\AdPolicy;
use App\Services\Ads\AdService;
use App\Services\MobileApi\Presenter;

/**
 * Реклама на площадке из приложения (то же, что AdController сайта): мои кампании, создание с баннером,
 * оплата картой, пауза / возобновление, повторная отправка после отклонения. Сумму считает только сервер.
 */
final class AdApiController extends BaseMobileController
{
    private const ERRORS = [
        'disabled' => 'err_disabled', 'forbidden' => 'err_forbidden', 'bad_format' => 'err_format',
        'bad_units' => 'err_units', 'bad_category' => 'err_category', 'bad_url' => 'err_url',
        'bad_image' => 'err_image', 'bad_title' => 'err_title', 'bad_status' => 'err_status',
        'slot_taken' => 'err_slot', 'gateway_not_configured' => 'err_gateway', 'gateway_init_failed' => 'err_gateway',
        'not_found' => 'err_not_found',
    ];

    /** GET /ads — кампании, форматы с тарифами, категории */
    public function index(): void
    {
        $uid = $this->requireUserId();
        $svc = new AdService();
        $flags = $svc->flags();
        $formats = [];
        foreach ([AdPolicy::FORMAT_HERO, AdPolicy::FORMAT_CATEGORY, AdPolicy::FORMAT_SIDEBAR, AdPolicy::FORMAT_EXCLUSIVE] as $f) {
            $exclusive = $f === AdPolicy::FORMAT_EXCLUSIVE;
            $formats[] = [
                'code' => $f,
                'label' => t('ads.format_' . $f),
                'enabled' => (bool) ($flags[$f] ?? false),
                'unit' => $exclusive ? 'week' : 'thousand',
                'unit_price' => $exclusive ? AdPolicy::EXCLUSIVE_WEEK_KZT : (int) AdPolicy::CPM_KZT[$f],
                'max_units' => $exclusive ? AdPolicy::MAX_WEEKS : AdPolicy::MAX_THOUSANDS,
                'category' => match ($f) {
                    AdPolicy::FORMAT_HERO => 'none',
                    AdPolicy::FORMAT_SIDEBAR => 'optional',
                    default => 'required',
                },
            ];
        }
        $categories = [];
        foreach (ProductHelper::PRODUCT_CATEGORY_TREE as $parent => $children) {
            $categories[] = ['value' => $parent, 'label' => ProductHelper::categoryLabel($parent), 'parent' => null];
            foreach ($children as $child) {
                $categories[] = [
                    'value' => ProductHelper::formatCategory($parent, $child),
                    'label' => ProductHelper::categoryLabel($child),
                    'parent' => ProductHelper::categoryLabel($parent),
                ];
            }
        }
        $this->ok([
            'campaigns' => array_map(fn (array $c): array => $this->campaign($c), $svc->mine($uid)),
            'formats' => $formats,
            'categories' => $categories,
        ]);
    }

    /** POST /ads (multipart: format, units, category?, title, target_url, image) */
    public function create(): void
    {
        $uid = $this->requireUserId();
        $res = (new AdService())->create($uid, $this->fields(), $_FILES['image'] ?? null);
        if (empty($res['ok'])) {
            $this->adFail($res);
        }
        $this->ok(['message' => t('ads.created', ['amount' => (string) ($res['amount'] ?? '')])], [], 201);
    }

    /** POST /ads/{id}/resubmit (multipart: title, target_url, image?) */
    public function resubmit(string $id): void
    {
        $uid = $this->requireUserId();
        $res = (new AdService())->resubmit($uid, (int) $id, $this->fields(), $_FILES['image'] ?? null);
        if (empty($res['ok'])) {
            $this->adFail($res);
        }
        $this->ok(['message' => t('ads.resubmitted')]);
    }

    /** POST /ads/{id}/pay {idempotency_key} → {payment_url} | {already_running} */
    public function pay(string $id): void
    {
        $uid = $this->requireUserId();
        $res = (new AdService())->beginPayment($uid, (int) $id, ['idempotency_key' => $this->input('idempotency_key') ?: null]);
        if (!empty($res['already_applied'])) {
            $this->ok(['already_running' => true, 'message' => t('ads.already_running')]);
        }
        if (empty($res['redirect_url'])) {
            $this->adFail($res);
        }
        $url = (string) $res['redirect_url'];
        if (preg_match('#/payments/halyk/sim/([a-f0-9]{32})#', $url, $m)) {
            $url = '/payments/halyk/app/' . $m[1];
        }
        $this->ok(['payment_url' => preg_match('#^https?://#', $url) ? $url : ProductHelper::absoluteUrl($url)]);
    }

    /** POST /ads/{id}/pause, /ads/{id}/resume */
    public function pause(string $id): void
    {
        $this->running((int) $id, true);
    }

    public function resume(string $id): void
    {
        $this->running((int) $id, false);
    }

    // ---------------------------------------------------------------- helpers

    private function running(int $id, bool $pause): never
    {
        $uid = $this->requireUserId();
        $res = (new AdService())->setRunning($uid, $id, $pause);
        if (empty($res['ok'])) {
            $this->adFail($res);
        }
        $this->ok(['message' => t($pause ? 'ads.paused' : 'ads.resumed')]);
    }

    /** @return array<string, string> */
    private function fields(): array
    {
        $out = [];
        foreach (['format', 'units', 'category', 'title', 'target_url'] as $k) {
            $v = $this->body()[$k] ?? null;
            if (is_scalar($v)) {
                $out[$k] = trim((string) $v);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $res */
    private function adFail(array $res): never
    {
        $code = (string) ($res['error_code'] ?? 'generic');
        $this->fail('ads_' . $code, 422, t('ads.' . (self::ERRORS[$code] ?? 'err_generic')));
    }

    /** @param array<string, mixed> $c */
    private function campaign(array $c): array
    {
        $status = (string) $c['status'];
        $format = (string) $c['format'];

        return [
            'id' => (int) $c['id'],
            'format' => $format,
            'format_label' => t('ads.format_' . $format),
            'title' => (string) $c['title'],
            'target_url' => (string) $c['target_url'],
            'image_url' => ($c['image_file'] ?? '') !== ''
                ? Presenter::media(ProductHelper::url('public/uploads/ads/' . basename((string) $c['image_file']))) : null,
            'category' => ($c['category_key'] ?? '') !== '' ? (string) $c['category_key'] : null,
            'units' => (int) $c['units'],
            'amount' => (int) $c['amount'],
            'impressions_paid' => (int) ($c['impressions_paid'] ?? 0),
            'impressions_used' => (int) ($c['impressions_used'] ?? 0),
            'clicks' => (int) ($c['clicks'] ?? 0),
            'status' => $status,
            'status_label' => t('ads.status_' . $status, [], $status),
            'reject_reason' => ($c['reject_reason'] ?? '') !== '' ? (string) $c['reject_reason'] : null,
            'ends_at' => Presenter::date($c['ends_at'] ?? null),
            'can_pay' => in_array($status, [AdPolicy::STATUS_APPROVED, AdPolicy::STATUS_PENDING, AdPolicy::STATUS_FAILED], true),
            'can_pause' => $status === AdPolicy::STATUS_ACTIVE,
            'can_resume' => $status === AdPolicy::STATUS_PAUSED,
            'can_resubmit' => $status === AdPolicy::STATUS_REJECTED,
            'created_at' => Presenter::date($c['created_at'] ?? null),
        ];
    }
}
