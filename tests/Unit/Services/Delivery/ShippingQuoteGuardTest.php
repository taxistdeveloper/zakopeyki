<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\LogisticsProviderInterface;
use App\Services\Delivery\ShippingQuoteGuard;
use PHPUnit\Framework\TestCase;

final class ShippingQuoteGuardTest extends TestCase
{
    public function testRejectsExpiredQuoteAndRequestsNew(): void
    {
        $orders = new class {
            public bool $invalidated = false;
            public function invalidateQuotes(int $id, string $reason = ''): void
            {
                $this->invalidated = true;
            }
            public function packagingById(int $id): ?array
            {
                return null;
            }
            public function touchQuoteAfterRecalc(int $quoteId, array $fresh): void
            {
            }
        };

        $guard = new ShippingQuoteGuard($orders);
        $requested = false;
        $row = [
            'id' => 1,
            'shipping_version' => 1,
            'listing_shipping_version' => 1,
            'product_id' => 0,
            'sender' => [],
            'recipient' => [],
            'shipment' => [],
            'selected_quote' => [
                'id' => 10,
                'total_amount' => 1500,
                'service_code' => 'cdek_136',
                'shipping_version' => 1,
                'valid_until' => date('Y-m-d H:i:s', time() - 60),
                'currency' => 'KZT',
            ],
        ];

        $provider = new class implements LogisticsProviderInterface {
            public function getQuotes(array $context): array
            {
                return [];
            }
            public function createOrder(array $context): array
            {
                return ['logistics_order_id' => 'x'];
            }
            public function handleStatusWebhook(array $payload): ?array
            {
                return null;
            }
        };

        $result = $guard->revalidateForPayment(
            $row,
            function (int $id) use (&$requested): array {
                $requested = true;
                return ['ok' => true];
            },
            $provider
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
        $this->assertTrue($orders->invalidated);
        $this->assertTrue($requested);
    }

    public function testRejectsShippingVersionMismatch(): void
    {
        $orders = new class {
            public function invalidateQuotes(int $id, string $reason = ''): void
            {
            }
            public function packagingById(int $id): ?array
            {
                return null;
            }
            public function touchQuoteAfterRecalc(int $quoteId, array $fresh): void
            {
            }
        };

        $guard = new ShippingQuoteGuard($orders);
        $row = [
            'id' => 1,
            'shipping_version' => 8,
            'listing_shipping_version' => 1,
            'product_id' => 0,
            'sender' => [],
            'recipient' => [],
            'shipment' => [],
            'selected_quote' => [
                'id' => 10,
                'total_amount' => 1500,
                'service_code' => 'cdek_136',
                'shipping_version' => 7,
                'valid_until' => date('Y-m-d H:i:s', time() + 3600),
                'currency' => 'KZT',
            ],
        ];

        $provider = new class implements LogisticsProviderInterface {
            public function getQuotes(array $context): array
            {
                return [];
            }
            public function createOrder(array $context): array
            {
                return ['logistics_order_id' => 'x'];
            }
            public function handleStatusWebhook(array $payload): ?array
            {
                return null;
            }
        };

        $result = $guard->revalidateForPayment($row, static fn(): array => ['ok' => true], $provider);
        $this->assertFalse($result['ok']);
        $this->assertSame('shipping_version', $result['reason']);
    }

    public function testRejectsPriceChange(): void
    {
        $orders = new class {
            public function invalidateQuotes(int $id, string $reason = ''): void
            {
            }
            public function packagingById(int $id): ?array
            {
                return null;
            }
            public function touchQuoteAfterRecalc(int $quoteId, array $fresh): void
            {
            }
        };

        $guard = new ShippingQuoteGuard($orders);
        $row = [
            'id' => 1,
            'shipping_version' => 3,
            'listing_shipping_version' => 3,
            'product_id' => 0,
            'sender' => ['city' => 'Almaty'],
            'recipient' => ['city' => 'Astana'],
            'shipment' => ['gross_weight' => 1],
            'selected_quote' => [
                'id' => 10,
                'total_amount' => 1500,
                'service_code' => 'cdek_136',
                'shipping_version' => 3,
                'valid_until' => date('Y-m-d H:i:s', time() + 3600),
                'currency' => 'KZT',
            ],
        ];

        $provider = new class implements LogisticsProviderInterface {
            public function getQuotes(array $context): array
            {
                return [[
                    'service_code' => 'cdek_136',
                    'total_amount' => 1800,
                    'valid_until' => date('Y-m-d H:i:s', time() + 3600),
                ]];
            }
            public function createOrder(array $context): array
            {
                return ['logistics_order_id' => 'x'];
            }
            public function handleStatusWebhook(array $payload): ?array
            {
                return null;
            }
        };

        $result = $guard->revalidateForPayment($row, static fn(): array => ['ok' => true], $provider);
        $this->assertFalse($result['ok']);
        $this->assertSame('price_changed', $result['reason']);
        $this->assertSame(1500, $result['old_amount']);
        $this->assertSame(1800, $result['new_amount']);
    }

    public function testAcceptsUnchangedPrice(): void
    {
        $touched = false;
        $orders = new class {
            public bool $touchedFlag = false;
            public function invalidateQuotes(int $id, string $reason = ''): void
            {
                throw new \RuntimeException('must not invalidate');
            }
            public function packagingById(int $id): ?array
            {
                return null;
            }
            public function touchQuoteAfterRecalc(int $quoteId, array $fresh): void
            {
                $this->touchedFlag = true;
            }
        };

        $guard = new ShippingQuoteGuard($orders);
        $row = [
            'id' => 1,
            'shipping_version' => 2,
            'listing_shipping_version' => 2,
            'product_id' => 0,
            'sender' => [],
            'recipient' => [],
            'shipment' => [],
            'selected_quote' => [
                'id' => 10,
                'total_amount' => 2000,
                'service_code' => 'cdek_139',
                'shipping_version' => 2,
                'valid_until' => date('Y-m-d H:i:s', time() + 3600),
                'currency' => 'KZT',
            ],
        ];

        $provider = new class implements LogisticsProviderInterface {
            public function getQuotes(array $context): array
            {
                return [[
                    'service_code' => 'cdek_139',
                    'total_amount' => 2000,
                    'valid_until' => date('Y-m-d H:i:s', time() + 7200),
                    'response_hash' => 'abc',
                ]];
            }
            public function createOrder(array $context): array
            {
                return ['logistics_order_id' => 'x'];
            }
            public function handleStatusWebhook(array $payload): ?array
            {
                return null;
            }
        };

        $result = $guard->revalidateForPayment($row, static fn(): array => ['ok' => true], $provider);
        $this->assertTrue($result['ok']);
        $this->assertSame(2000, $result['amount']);
        $this->assertTrue($orders->touchedFlag);
    }
}
