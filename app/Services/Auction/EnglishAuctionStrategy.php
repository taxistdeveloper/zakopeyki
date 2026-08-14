<?php

declare(strict_types=1);

namespace App\Services\Auction;

class EnglishAuctionStrategy implements AuctionStrategyInterface
{
    public function getCurrentPrice(array $auction): int
    {
        return (int) ($auction['current_bid'] ?: $auction['price'] ?? 0);
    }

    public function validateBid(array $auction, int $amount, int $userId): array
    {
        $errors = [];
        if (($auction['status'] ?? '') !== 'active') {
            return [t('auctions.err_inactive')];
        }

        $endAt = $auction['auction_end_at'] ?? null;
        if ($endAt && time() >= strtotime((string) $endAt)) {
            $errors[] = t('auctions.err_ended');
        }

        $min = $this->getCurrentPrice($auction) + max(1, (int) ($auction['bid_step'] ?? 1));
        if ($amount < $min) {
            $errors[] = t('auctions.err_min_bid', ['min' => number_format($min, 0, '', ' ')]);
        }

        return $errors;
    }

    public function processBid(array &$auction, int $amount, int $userId): void
    {
        $now = time();
        $auction['current_bid'] = $amount;
        $auction['last_bid_at'] = date('Y-m-d H:i:s', $now);

        $endAt = $auction['auction_end_at'] ?? null;
        if ($endAt) {
            $endTs = strtotime((string) $endAt);
            $remaining = $endTs - $now;
            $threshold = (int) ($auction['anti_snipe_seconds'] ?? 30);
            $extend = (int) ($auction['auto_extend_seconds'] ?? 120);
            if ($remaining > 0 && $remaining <= $threshold && $extend > 0) {
                $auction['auction_end_at'] = date('Y-m-d H:i:s', $endTs + $extend);
            }
        }

        $buyNow = (int) ($auction['auction_buy_now'] ?? 0);
        if ($buyNow > 0 && $amount >= $buyNow) {
            $auction['status'] = 'sold';
            $auction['winner_user_id'] = $userId;
            $auction['auction_end_at'] = date('Y-m-d H:i:s', $now);
        }
    }
}
