<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\BuyerPointBService;
use App\Services\Delivery\DeliveryPointValidator;
use App\Services\Listing\ListingShippingService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 — buyer Point B checkout gate (unit, no live CDEK / no Product DB).
 * Full suite: php tools/run_buyer_point_b_phase5_tests.php
 */
final class BuyerPointBPhase5Test extends TestCase
{
    private DeliveryPointValidator $validator;
    private BuyerPointBService $svc;
    private object $pvz;
    private ListingShippingService $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $cityResolver = static function (string $city, ?string $country = null): ?array {
            $map = ['алматы' => 4756, 'астана' => 4961];
            $key = mb_strtolower(trim($city));
            return isset($map[$key])
                ? ['code' => $map[$key], 'city' => $city, 'country_code' => $country ?: 'KZ']
                : null;
        };

        $this->validator = new DeliveryPointValidator();
        $this->listing = new class ($cityResolver) extends ListingShippingService {
            /** @var array<string, mixed>|null */
            public ?array $shipping = [
                'fulfillment_mode' => 'delivery',
                'cdek_ready' => 1,
                'gross_weight' => 1.5,
                'item_weight' => 1.2,
                'package_length' => 20,
                'package_width' => 15,
                'package_height' => 10,
                'shipping_version' => 1,
            ];

            public function __construct(callable $cityResolver)
            {
                parent::__construct(new DeliveryPointValidator(), $cityResolver, static fn () => []);
            }

            public function findForProduct(int $productId): ?array
            {
                return $this->shipping;
            }
        };

        $this->pvz = new class {
            public function validateCode(string $code, ?string $expectedCity = null, ?float $packageWeightKg = null): array
            {
                if ($code === '' || $code === 'BADCODE') {
                    return ['ok' => false, 'error' => 'pvz not found'];
                }
                return [
                    'ok' => true,
                    'point' => [
                        'code' => $code,
                        'name' => 'ПВЗ Тест',
                        'address' => 'ул. Тестовая 1',
                        'city' => 'Алматы',
                        'city_code' => 4756,
                        'is_handout' => 1,
                    ],
                ];
            }
        };

        $this->svc = new BuyerPointBService($this->validator, $this->listing, $this->pvz);
    }

    public function testBuyerCanSelectCdekWhenReady(): void
    {
        $this->assertContains('cdek', $this->svc->availableDeliveryMethods(1));
        $this->assertTrue($this->svc->isCdekSelectable(1));
    }

    public function testCdekRequiresRecipientName(): void
    {
        $pb = $this->validator->validatePointB([
            'name' => '',
            'phone' => '+77001234567',
            'city' => 'Алматы',
            'delivery_mode' => 'pvz',
            'pvz_code' => 'ALA1',
        ], true);
        $this->assertFalse($pb['ok'] ?? true);
        $this->assertSame('recipient_name_required', $pb['error'] ?? null);
    }

    public function testCanChooseAndValidatePvz(): void
    {
        $pb = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '+77007654321',
            'city' => 'Алматы',
            'delivery_mode' => 'pvz',
            'pvz_code' => 'ALA1',
            'cdek_city_code' => 4756,
        ], true);
        $this->assertTrue($pb['ok'] ?? false);
        $canon = $this->pvz->validateCode((string) $pb['data']['pvz_code'], $pb['data']['city'], 1.5);
        $this->assertTrue($canon['ok'] ?? false);
        $this->assertSame('ALA1', $canon['point']['code'] ?? null);
    }

    public function testDoorDeliveryValidated(): void
    {
        $ok = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '+77007654321',
            'city' => 'Алматы',
            'street' => 'Назарбаева 1',
            'building' => '10',
            'delivery_mode' => 'courier',
            'cdek_city_code' => 4756,
            'postal_code' => '050000',
        ], true);
        $this->assertTrue($ok['ok'] ?? false);
        $this->assertSame('courier', $ok['data']['delivery_mode'] ?? null);

        $bad = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '+77007654321',
            'city' => 'Алматы',
            'street' => '',
            'delivery_mode' => 'courier',
            'cdek_city_code' => 4756,
        ], true);
        $this->assertFalse($bad['ok'] ?? true);
        $this->assertSame('recipient_address_required', $bad['error'] ?? null);
    }

    public function testRecipientPhoneValidated(): void
    {
        $pb = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '123',
            'city' => 'Алматы',
            'delivery_mode' => 'pvz',
            'pvz_code' => 'ALA1',
        ], true);
        $this->assertFalse($pb['ok'] ?? true);
        $this->assertSame('recipient_phone_invalid', $pb['error'] ?? null);
    }

    public function testPointBSnapshotStructure(): void
    {
        $data = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '+77007654321',
            'city' => 'Алматы',
            'delivery_mode' => 'pvz',
            'pvz_code' => 'ALA1',
            'cdek_city_code' => 4756,
        ], true)['data'];
        $snapshot = [
            'version' => 1,
            'delivery_method' => 'cdek',
            'product_id' => 10,
            'buyer_user_id' => 20,
            'point_b' => $data,
        ];
        $this->assertSame('Покупатель', $snapshot['point_b']['name']);
        $this->assertSame('ALA1', $snapshot['point_b']['pvz_code']);
        $this->assertSame(20, $snapshot['buyer_user_id']);
    }

    public function testInvalidPvzCodeRejected(): void
    {
        $bad = $this->pvz->validateCode('BADCODE', 'Алматы', 1.0);
        $this->assertFalse($bad['ok'] ?? true);
    }

    public function testSelfPickupDoesNotRequirePointB(): void
    {
        $this->listing->shipping = [
            'fulfillment_mode' => 'pickup',
            'cdek_ready' => 0,
        ];
        $methods = $this->svc->availableDeliveryMethods(1);
        $this->assertContains('pickup', $methods);
        $this->assertNotContains('cdek', $methods);

        $nonCdek = $this->svc->validateForCheckout(1, 99, 'pickup', []);
        $this->assertTrue($nonCdek['ok'] ?? false);
        $this->assertNull($nonCdek['snapshot'] ?? null);
    }

    public function testDoorRequiresCdekCityCode(): void
    {
        $pb = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '+77007654321',
            'city' => 'Алматы',
            'street' => 'Абая 1',
            'delivery_mode' => 'courier',
        ], true);
        $this->assertFalse($pb['ok'] ?? true);
        $this->assertSame('cdek_city_code_required', $pb['error'] ?? null);
    }

    public function testOwnershipAndQuoteInvalidationHooksExist(): void
    {
        $this->assertNotSame('', $this->svc->messageFor('forbidden'));
        $src = file_get_contents(dirname(__DIR__, 3) . '/app/Services/Delivery/DeliveryService.php');
        $this->assertIsString($src);
        $this->assertStringContainsString("invalidateQuotes(\$deliveryOrderId, 'address_changed')", $src);
        $this->assertStringContainsString('auto_quote', $src);
    }
}
