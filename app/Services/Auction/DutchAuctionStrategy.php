<?php

declare(strict_types=1);

namespace App\Services\Auction;

class DutchAuctionStrategy implements AuctionStrategyInterface
{
    public function getCurrentPrice(array $auction): int
    {
        $startPrice = (int) ($auction['price'] ?? 0);
        $startAt = (string) ($auction['auction_start_at'] ?? $auction['created_at'] ?? '');
        $startTs = $startAt !== '' ? strtotime($startAt) : time();
        $now = time();
        if ($now <= $startTs) {
            return $startPrice;
        }

        $interval = (int) ($auction['auction_step_interval'] ?? 60);
        if ($interval <= 0) {
            $interval = 60;
        }

        $step = (int) ($auction['bid_step'] ?? 0);
        $stepsPassed = (int) floor(($now - $startTs) / $interval);
        $calculated = $startPrice - ($stepsPassed * $step);
        $minPrice = (int) ($auction['auction_min_price'] ?? 0);

        return max($minPrice, $calculated);
    }

    public function validateBid(array $auction, int $amount, int $userId): array
    {
        if (($auction['status'] ?? '') !== 'active') {
            return [t('auctions.err_inactive')];
        }

        $endAt = $auction['auction_end_at'] ?? null;
        if ($endAt && time() >= strtotime((string) $endAt)) {
            return [t('auctions.err_ended')];
        }

        $current = $this->getCurrentPrice($auction);
        if (abs($amount - $current) > 0) {
            return [t('auctions.err_dutch_price', ['price' => number_format($current, 0, '', ' ')])];
        }

        return [];
    }

    public function processBid(array &$auction, int $amount, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $auction['current_bid'] = $amount;
        $auction['status'] = 'sold';
        $auction['winner_user_id'] = $userId;
        $auction['auction_end_at'] = $now;
        $auction['last_bid_at'] = $now;
    }
}
