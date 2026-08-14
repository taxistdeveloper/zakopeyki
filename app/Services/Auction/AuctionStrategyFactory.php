<?php

declare(strict_types=1);

namespace App\Services\Auction;

class AuctionStrategyFactory
{
    public static function kind(array $auction): string
    {
        $kind = (string) ($auction['auction_kind'] ?? 'english');
        return in_array($kind, ['english', 'dutch', 'continuous'], true) ? $kind : 'english';
    }

    public static function make(string $kind): AuctionStrategyInterface
    {
        return match ($kind) {
            'dutch' => new DutchAuctionStrategy(),
            'continuous' => new ContinuousAuctionStrategy(),
            default => new EnglishAuctionStrategy(),
        };
    }

    public static function forAuction(array $auction): AuctionStrategyInterface
    {
        return self::make(self::kind($auction));
    }
}
