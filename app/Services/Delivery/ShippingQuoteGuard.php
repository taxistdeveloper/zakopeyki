<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Models\ProductListingShipping;

/**
 * Финальная проверка quote перед оплатой доставки.
 *
 * Не доверяет цене из frontend / устаревшему quote:
 * - ownership / status (вызывающий слой);
 * - shipping_version;
 * - listing_shipping_version;
 * - TTL;
 * - повторный расчёт у провайдера и сравнение total_amount (INT).
 *
 * Если цена изменилась — старые quotes инвалидируются, делается новый requestQuotes.
 */
class ShippingQuoteGuard
{
    /** @var object DeliveryOrder-like (invalidateQuotes, packagingById, touchQuoteAfterRecalc) */
    private object $orders;

    public function __construct(?object $orders = null)
    {
        $this->orders = $orders ?? new DeliveryOrder();
    }

    /**
     * @param array<string, mixed> $row findWithDetails (+ product_id желателен)
     * @param callable(int): array{ok: bool, error?: string} $requestQuotes
     * @return array{
     *   ok: bool,
     *   amount?: int,
     *   quote?: array<string, mixed>,
     *   error?: string,
     *   reason?: string,
     *   old_amount?: int,
     *   new_amount?: int,
     *   currency?: string
     * }
     */
    public function revalidateForPayment(array $row, callable $requestQuotes, LogisticsProviderInterface $provider): array
    {
        $deliveryOrderId = (int) ($row['id'] ?? 0);
        $quote = $row['selected_quote'] ?? null;
        if (!$quote || empty($quote['id'])) {
            return ['ok' => false, 'error' => t('delivery.quote_not_found'), 'reason' => 'missing_quote'];
        }

        $oldAmount = (int) ($quote['total_amount'] ?? 0);
        if ($oldAmount <= 0) {
            return ['ok' => false, 'error' => t('delivery.invalid_amount'), 'reason' => 'invalid_amount'];
        }

        if (strtotime((string) $quote['valid_until']) < time()) {
            $this->orders->invalidateQuotes($deliveryOrderId, 'expired_at_payment');
            $requestQuotes($deliveryOrderId);
            return [
                'ok' => false,
                'error' => t('delivery.quote_expired'),
                'reason' => 'expired',
                'old_amount' => $oldAmount,
            ];
        }

        $orderVersion = (int) ($row['shipping_version'] ?? 1);
        $quoteVersion = (int) ($quote['shipping_version'] ?? 0);
        if ($quoteVersion > 0 && $quoteVersion !== $orderVersion) {
            $this->orders->invalidateQuotes($deliveryOrderId, 'shipping_version_mismatch');
            $requestQuotes($deliveryOrderId);
            return [
                'ok' => false,
                'error' => t('delivery.quote_stale_version'),
                'reason' => 'shipping_version',
                'old_amount' => $oldAmount,
            ];
        }

        $listingCheck = $this->assertListingVersion($row, $quote);
        if (!$listingCheck['ok']) {
            $this->orders->invalidateQuotes($deliveryOrderId, 'listing_shipping_version_mismatch');
            $requestQuotes($deliveryOrderId);
            return $listingCheck + ['old_amount' => $oldAmount];
        }

        $packagingPrice = 0;
        if (!empty($row['shipment']['packaging_id'])) {
            $pack = $this->orders->packagingById((int) $row['shipment']['packaging_id']);
            $packagingPrice = (int) ($pack['price_amount'] ?? 0);
        }

        $freshQuotes = $provider->getQuotes([
            'delivery_order_id' => $deliveryOrderId,
            'sender' => $row['sender'] ?? [],
            'recipient' => $row['recipient'] ?? [],
            'shipment' => $row['shipment'] ?? [],
            'packaging_price' => $packagingPrice,
        ]);

        if ($freshQuotes === []) {
            return [
                'ok' => false,
                'error' => t('delivery.quote_recalc_failed'),
                'reason' => 'recalc_empty',
                'old_amount' => $oldAmount,
            ];
        }

        $serviceCode = (string) ($quote['service_code'] ?? '');
        $match = null;
        foreach ($freshQuotes as $fq) {
            if ((string) ($fq['service_code'] ?? '') === $serviceCode) {
                $match = $fq;
                break;
            }
        }

        if ($match === null) {
            $this->orders->invalidateQuotes($deliveryOrderId, 'tariff_unavailable_at_payment');
            $requestQuotes($deliveryOrderId);
            return [
                'ok' => false,
                'error' => t('delivery.quote_tariff_gone'),
                'reason' => 'tariff_gone',
                'old_amount' => $oldAmount,
            ];
        }

        $newAmount = (int) ($match['total_amount'] ?? 0);
        // Сравнение только INT — без float.
        if ($newAmount !== $oldAmount) {
            $this->orders->invalidateQuotes($deliveryOrderId, 'price_changed_at_payment');
            $requestQuotes($deliveryOrderId);
            return [
                'ok' => false,
                'error' => t('delivery.quote_price_changed', [
                    'old' => (string) $oldAmount,
                    'new' => (string) $newAmount,
                ]),
                'reason' => 'price_changed',
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'currency' => (string) ($quote['currency'] ?? 'KZT'),
            ];
        }

        // Цена совпала — продлеваем TTL и фиксируем audit recalculation.
        $this->orders->touchQuoteAfterRecalc((int) $quote['id'], $match);

        return [
            'ok' => true,
            'amount' => $oldAmount,
            'quote' => $quote,
            'currency' => (string) ($quote['currency'] ?? 'KZT'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $quote
     * @return array{ok: bool, error?: string, reason?: string}
     */
    private function assertListingVersion(array $row, array $quote): array
    {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['ok' => true];
        }

        $listing = (new ProductListingShipping())->findByProductId($productId);
        if (!$listing) {
            return ['ok' => true];
        }

        $currentListingVersion = (int) ($listing['shipping_version'] ?? 1);
        $baseline = (int) ($row['listing_shipping_version'] ?? 0);
        if ($baseline <= 0) {
            $snap = $this->decodeSnapshot($quote);
            $baseline = (int) ($snap['listing_shipping_version'] ?? $currentListingVersion);
        }

        if ($currentListingVersion !== $baseline) {
            return [
                'ok' => false,
                'error' => t('delivery.quote_stale_listing'),
                'reason' => 'listing_shipping_version',
            ];
        }

        return ['ok' => true];
    }

    /** @param array<string, mixed> $quote */
    private function decodeSnapshot(array $quote): array
    {
        $raw = $quote['snapshot_json'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
