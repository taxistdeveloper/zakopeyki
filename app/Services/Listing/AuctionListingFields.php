<?php

declare(strict_types=1);

namespace App\Services\Listing;

/**
 * Поля аукциона из формы объявления (перенесено из ProfileController::auctionFieldsFromPost
 * без изменения правил; вход — массив вместо $_POST, чтобы использовать в мобильном API).
 */
final class AuctionListingFields
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{error?: string, fields: array<string, mixed>}
     */
    public function fromInput(string $type, int $startPrice, array $input, ?array $existing = null): array
    {
        if ($type !== 'auction') {
            return ['fields' => []];
        }

        $kind = (string) ($input['auction_kind'] ?? ($existing['auction_kind'] ?? 'english'));
        if (!in_array($kind, ['english', 'dutch', 'continuous'], true)) {
            $kind = 'english';
        }

        $bidStep = (int) preg_replace('/\D/', '', (string) ($input['bid_step'] ?? ($existing['bid_step'] ?? 1000)));
        if ($bidStep < 1) {
            $bidStep = 1000;
        }

        $hours = (int) ($input['auction_hours'] ?? 24);
        if (!in_array($hours, [1, 6, 24, 72, 168], true)) {
            $hours = 24;
        }

        $reserveRaw = preg_replace('/\D/', '', (string) ($input['auction_reserve'] ?? ''));
        $reserve = $reserveRaw !== '' ? (int) $reserveRaw : null;

        $buyNowRaw = preg_replace('/\D/', '', (string) ($input['auction_buy_now'] ?? ''));
        $buyNow = $buyNowRaw !== '' ? (int) $buyNowRaw : null;
        if ($kind !== 'dutch' && $buyNow !== null && $buyNow <= $startPrice) {
            return ['error' => t('profile.auction_buy_now_invalid'), 'fields' => []];
        }

        $fields = [
            'auction_kind' => $kind,
            'bid_step' => $bidStep,
            'anti_snipe_seconds' => 30,
            'auto_extend_seconds' => 120,
            'auction_start_at' => $existing['auction_start_at'] ?? date('Y-m-d H:i:s'),
            'auction_reserve' => $reserve,
            'auction_buy_now' => $kind === 'dutch' ? null : $buyNow,
            'auction_min_price' => null,
            'auction_step_interval' => null,
            'auction_end_at' => null,
            'inactivity_timeout_seconds' => null,
        ];

        if ($kind === 'continuous') {
            $inactivityHours = (int) ($input['inactivity_hours'] ?? 24);
            if (!in_array($inactivityHours, [1, 6, 24, 72, 168], true)) {
                $inactivityHours = 24;
            }
            $fields['inactivity_timeout_seconds'] = $inactivityHours * 3600;
            $fields['auction_end_at'] = null;
        } else {
            $startTs = !empty($existing['auction_start_at'])
                ? strtotime((string) $existing['auction_start_at'])
                : time();
            $fields['auction_end_at'] = date('Y-m-d H:i:s', $startTs + ($hours * 3600));
        }

        if ($kind === 'dutch') {
            $minPrice = (int) preg_replace('/\D/', '', (string) ($input['auction_min_price'] ?? '0'));
            if ($minPrice < 1 || $minPrice >= $startPrice) {
                return ['error' => t('profile.auction_min_invalid'), 'fields' => []];
            }
            $intervalMin = (int) ($input['auction_step_minutes'] ?? 1);
            if ($intervalMin < 1) {
                $intervalMin = 1;
            }
            $fields['auction_min_price'] = $minPrice;
            $fields['auction_step_interval'] = $intervalMin * 60;
        }

        if ($startPrice < 1) {
            return ['error' => t('profile.auction_start_required'), 'fields' => []];
        }

        return ['fields' => $fields];
    }
}
