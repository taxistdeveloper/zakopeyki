<?php

declare(strict_types=1);

namespace App\Services\Auction;

interface AuctionStrategyInterface
{
    public function getCurrentPrice(array $auction): int;

    /** @return list<string> */
    public function validateBid(array $auction, int $amount, int $userId): array;

    public function processBid(array &$auction, int $amount, int $userId): void;
}
