<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\Auction\AuctionStrategyFactory;
use PDO;
use Throwable;

class AuctionService
{
    private PDO $db;
    private Product $products;
    private Wallet $wallet;

    public function __construct(?Product $products = null, ?Wallet $wallet = null)
    {
        $this->products = $products ?? new Product();
        $this->wallet = $wallet ?? new Wallet();
        $this->db = Database::connect();
    }

    public static function listedBuyNow(array $auction): ?int
    {
        $kind = AuctionStrategyFactory::kind($auction);
        if ($kind === 'dutch') {
            return AuctionStrategyFactory::make('dutch')->getCurrentPrice($auction);
        }
        $price = (int) ($auction['auction_buy_now'] ?? 0);
        return $price > 0 ? $price : null;
    }

    public static function availableBuyNow(array $auction): ?int
    {
        if (($auction['status'] ?? 'active') !== 'active') {
            return null;
        }
        $price = self::listedBuyNow($auction);
        if ($price === null) {
            return null;
        }
        if (AuctionStrategyFactory::kind($auction) === 'dutch') {
            return $price;
        }
        $current = AuctionStrategyFactory::forAuction($auction)->getCurrentPrice($auction);
        return $price > $current ? $price : null;
    }

    public function enrich(array $auction): array
    {
        $kind = AuctionStrategyFactory::kind($auction);
        $strategy = AuctionStrategyFactory::make($kind);
        $auction['auction_kind'] = $kind;
        $auction['calculated_current_price'] = $strategy->getCurrentPrice($auction);
        $auction['auction_kind_label'] = t('auctions.kind_' . $kind);
        $auction['buy_now_price'] = self::availableBuyNow($auction);
        return $auction;
    }

    /** @return list<array> */
    public function listActive(): array
    {
        $items = $this->products->allActive('auction');
        $counts = $this->products->countBidsForProducts(array_map(static fn ($row) => (int) $row['id'], $items));
        return array_map(function (array $row) use ($counts) {
            $data = $this->enrich($row);
            $data['bid_count'] = $counts[(int) $row['id']] ?? 0;
            return $data;
        }, $items);
    }

    public function details(int $productId): ?array
    {
        $auction = $this->products->find($productId);
        if (!$auction || ($auction['type'] ?? '') !== 'auction') {
            return null;
        }
        $data = $this->enrich($auction);
        $data['bids'] = $this->products->recentBids($productId, 20);
        $data['bid_count'] = $this->products->countBids($productId);
        return $data;
    }

    public function livePayload(int $productId): ?array
    {
        $data = $this->details($productId);
        if (!$data) {
            return null;
        }

        return [
            'id' => (int) $data['id'],
            'status' => $data['status'],
            'kind' => $data['auction_kind'],
            'current_price' => (int) $data['calculated_current_price'],
            'bid_step' => (int) ($data['bid_step'] ?? 0),
            'end_at' => $data['auction_end_at'] ?? null,
            'last_bid_at' => $data['last_bid_at'] ?? null,
            'inactivity_timeout_seconds' => $data['inactivity_timeout_seconds'] ?? null,
            'anti_snipe_seconds' => (int) ($data['anti_snipe_seconds'] ?? 30),
            'winner_user_id' => $data['winner_user_id'] ?? null,
            'buy_now_price' => $data['buy_now_price'] ?? null,
            'bids' => array_map(static function (array $bid): array {
                return [
                    'user' => $bid['bidder_name'] ?? ('#' . $bid['user_id']),
                    'amount' => (int) $bid['amount'],
                    'created_at' => $bid['created_at'] ?? null,
                ];
            }, $data['bids'] ?? []),
        ];
    }

    /**
     * @return array{ok: bool, error?: string, amount?: int, current_price?: int, status?: string, end_at?: ?string, user_balance?: int}
     */
    public function placeBid(int $productId, int $userId, int $amount): array
    {
        $this->db->beginTransaction();
        try {
            $auction = $this->products->findAndLock($productId);
            if (!$auction || ($auction['type'] ?? '') !== 'auction') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_not_found')];
            }
            if ((int) $auction['user_id'] === $userId) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_own_lot')];
            }
            if (($auction['status'] ?? '') !== 'active') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_inactive')];
            }

            $kind = AuctionStrategyFactory::kind($auction);
            $strategy = AuctionStrategyFactory::make($kind);
            if ($kind === 'dutch') {
                $amount = $strategy->getCurrentPrice($auction);
            }

            $buyNowPrice = self::availableBuyNow($auction);
            $isBuyNow = $buyNowPrice !== null && $amount === $buyNowPrice;

            if ($isBuyNow) {
                $errors = [];
                if (($auction['status'] ?? '') !== 'active') {
                    $errors[] = t('auctions.err_inactive');
                }
                $endAt = $auction['auction_end_at'] ?? null;
                if ($endAt && time() >= strtotime((string) $endAt)) {
                    $errors[] = t('auctions.err_ended');
                }
            } else {
                $errors = $strategy->validateBid($auction, $amount, $userId);
            }
            if ($errors) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => implode(' ', $errors)];
            }

            $previous = $this->products->getHighestBid($productId);
            $toDeduct = $amount;
            if ($previous) {
                if ((int) $previous['user_id'] === $userId) {
                    $toDeduct = $amount - (int) $previous['amount'];
                } else {
                    $refund = $this->wallet->refundAuctionHold(
                        (int) $previous['user_id'],
                        (int) $previous['amount'],
                        $productId
                    );
                    if (!$refund['ok']) {
                        $this->db->rollBack();
                        return ['ok' => false, 'error' => $refund['error'] ?? t('wallet.op_failed')];
                    }
                }
            }

            if ($toDeduct > 0) {
                $hold = $this->wallet->holdForAuction($userId, $toDeduct, $productId);
                if (!$hold['ok']) {
                    $this->db->rollBack();
                    return ['ok' => false, 'error' => $hold['error'] ?? t('wallet.insufficient')];
                }
            }

            $strategy->processBid($auction, $amount, $userId);
            $bidId = $this->products->createBid($productId, $userId, $amount);

            if (($auction['status'] ?? '') === 'sold') {
                $auction['winner_user_id'] = $userId;
                $auction['winning_bid_id'] = $bidId;
            }

            $this->products->saveAuctionState($auction);
            $this->db->commit();

            return [
                'ok' => true,
                'amount' => $amount,
                'current_price' => (int) $auction['current_bid'],
                'status' => $auction['status'],
                'end_at' => $auction['auction_end_at'] ?? null,
                'user_balance' => $this->wallet->balance($userId),
                'buy_now' => $isBuyNow || $kind === 'dutch',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('auctions.err_bid_failed')];
        }
    }

    /**
     * @return array{ok: bool, error?: string, amount?: int, status?: string}
     */
    public function buyNow(int $productId, int $userId): array
    {
        $auction = $this->products->find($productId);
        if (!$auction || ($auction['type'] ?? '') !== 'auction') {
            return ['ok' => false, 'error' => t('auctions.err_not_found')];
        }
        $price = self::availableBuyNow($this->enrich($auction));
        if ($price === null) {
            return ['ok' => false, 'error' => t('auctions.err_no_buy_now')];
        }
        return $this->placeBid($productId, $userId, $price);
    }

    /**
     * Продавец принимает текущую высшую ставку (бессрочный лот).
     * @return array{ok: bool, error?: string}
     */
    public function acceptHighest(int $productId, int $sellerId): array
    {
        $this->db->beginTransaction();
        try {
            $auction = $this->products->findAndLock($productId);
            if (!$auction || ($auction['type'] ?? '') !== 'auction') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_not_found')];
            }
            if ((int) $auction['user_id'] !== $sellerId) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_not_seller')];
            }
            if (AuctionStrategyFactory::kind($auction) !== 'continuous') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_accept_kind')];
            }
            if (($auction['status'] ?? '') !== 'active') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_inactive')];
            }

            $highest = $this->products->getHighestBid($productId);
            if (!$highest) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('auctions.err_no_bids')];
            }

            $now = date('Y-m-d H:i:s');
            $auction['status'] = 'sold';
            $auction['winner_user_id'] = (int) $highest['user_id'];
            $auction['winning_bid_id'] = (int) $highest['id'];
            $auction['current_bid'] = (int) $highest['amount'];
            $auction['auction_end_at'] = $now;
            $this->products->saveAuctionState($auction);
            $this->db->commit();

            return ['ok' => true];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('auctions.err_bid_failed')];
        }
    }

    public function finalizeExpired(): int
    {
        $closed = 0;
        foreach ($this->products->getExpiredAuctions() as $row) {
            if ($this->finalizeOne((int) $row['id'])) {
                $closed++;
            }
        }
        return $closed;
    }

    public function updateDutchDisplayedPrices(): int
    {
        $updated = 0;
        try {
            $stmt = $this->db->query(
                "SELECT * FROM products WHERE type = 'auction' AND status = 'active' AND auction_kind = 'dutch'"
            );
        } catch (Throwable $e) {
            return 0;
        }
        while ($row = $stmt->fetch()) {
            $price = AuctionStrategyFactory::make('dutch')->getCurrentPrice($row);
            if ((int) ($row['current_bid'] ?? 0) !== $price) {
                $upd = $this->db->prepare('UPDATE products SET current_bid = ? WHERE id = ? AND status = ?');
                $upd->execute([$price, $row['id'], 'active']);
                $updated++;
            }
        }
        return $updated;
    }

    private function finalizeOne(int $productId): bool
    {
        $this->db->beginTransaction();
        try {
            $auction = $this->products->findAndLock($productId);
            if (!$auction || ($auction['status'] ?? '') !== 'active' || ($auction['type'] ?? '') !== 'auction') {
                $this->db->rollBack();
                return false;
            }

            $highest = $this->products->getHighestBid($productId);
            $kind = AuctionStrategyFactory::kind($auction);

            if ($highest) {
                $reserve = $auction['auction_reserve'] ?? null;
                $reserveApplies = $kind === 'english' && $reserve !== null && $reserve !== '' && (int) $reserve > 0;
                if ($reserveApplies && (int) $highest['amount'] < (int) $reserve) {
                    $this->wallet->refundAuctionHold((int) $highest['user_id'], (int) $highest['amount'], $productId);
                    $auction['status'] = 'archived';
                } else {
                    $auction['status'] = 'sold';
                    $auction['winner_user_id'] = (int) $highest['user_id'];
                    $auction['winning_bid_id'] = (int) $highest['id'];
                    $auction['current_bid'] = (int) $highest['amount'];
                }
            } else {
                $auction['status'] = 'archived';
            }

            if (empty($auction['auction_end_at'])) {
                $auction['auction_end_at'] = date('Y-m-d H:i:s');
            }
            $this->products->saveAuctionState($auction);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }
}
