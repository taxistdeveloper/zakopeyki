<?php

declare(strict_types=1);

namespace App\Services\Auction;

class ContinuousAuctionStrategy implements AuctionStrategyInterface
{
    public function getCurrentPrice(array $auction): int
    {
        return (int) ($auction['current_bid'] ?: $auction['price'] ?? 0);
    }

    public function validateBid(array $auction, int $amount, int $userId): array
    {
        if (($auction['status'] ?? '') !== 'active') {
            return [t('auctions.err_inactive')];
        }

        $lastBid = $auction['last_bid_at'] ?? null;
        $timeout = $auction['inactivity_timeout_seconds'] ?? null;
        if ($lastBid && $timeout) {
            if ((time() - strtotime((string) $lastBid)) >= (int) $timeout) {
                return [t('auctions.err_inactive_timeout')];
            }
        }

        $min = $this->getCurrentPrice($auction) + max(1, (int) ($auction['bid_step'] ?? 1));
        if ($amount < $min) {
            return [t('auctions.err_min_bid', ['min' => number_format($min, 0, '', ' ')])];
        }

        return [];
    }

    public function processBid(array &$auction, int $amount, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $auction['current_bid'] = $amount;
        $auction['last_bid_at'] = $now;

        $blitz = (int) ($auction['auction_buy_now'] ?? $auction['auction_reserve'] ?? 0);
        if ($blitz > 0 && $amount >= $blitz) {
            $auction['status'] = 'sold';
            $auction['winner_user_id'] = $userId;
            $auction['auction_end_at'] = $now;
        }
    }
}
