<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekErrorMapper;
use App\Services\Cdek\CdekOrderPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class CdekOrderPayloadBuilderTest extends TestCase
{
    private CdekOrderPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new CdekOrderPayloadBuilder();
    }

    /** @return array<string, mixed> */
    private function baseAvr(array $overrides = []): array
    {
        $base = [
            'delivery_order_id' => 42,
            'order_number' => 'DO-42',
            'sender' => [
                'name' => 'Seller Name',
                'phone' => '+77001112233',
                'city' => 'Алматы',
                'country' => 'KZ',
                'street' => 'Абая',
                'building' => '10',
            ],
            'recipient' => [
                'name' => 'Buyer Name',
                'phone' => '87001234567',
                'city' => 'Астана',
                'country' => 'KZ',
                'street' => 'Кабанбай батыра',
                'building' => '1',
                'delivery_mode' => 'courier',
            ],
            'shipment' => [
                'gross_weight' => '1.5',
                'package_length' => 30,
                'package_width' => 20,
                'package_height' => 10,
                'product_title' => 'Телефон',
            ],
            'service' => [
                'service_code' => 'cdek_136',
                'service_name' => 'Посылка',
            ],
        ];
        return array_replace_recursive($base, $overrides);
    }

    public function testBuildsOrderPayloadWithLocationsXor(): void
    {
        $result = $this->builder->buildFromAvr($this->baseAvr(), [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
            'type' => 1,
        ]);

        $this->assertTrue($result['ok'], $result['error']['message'] ?? '');
        $payload = $result['payload'];
        $this->assertSame(136, $payload['tariff_code']);
        $this->assertSame('DO-42', $payload['number']);
        $this->assertSame('zk-del-42', $payload['developer_key']);
        $this->assertSame(4756, $payload['from_location']['code']);
        $this->assertSame('Абая, 10', $payload['from_location']['address']);
        $this->assertArrayNotHasKey('shipment_point', $payload);
        $this->assertArrayNotHasKey('delivery_point', $payload);
        $this->assertSame(1500, $payload['packages'][0]['weight']);
        $this->assertSame('1', $payload['packages'][0]['number']);
        $this->assertSame(0, $payload['packages'][0]['items'][0]['payment']['value']);
        $this->assertSame('+77001234567', $payload['recipient']['phones'][0]['number']);
        // Calculator-only fields must not leak.
        $this->assertArrayNotHasKey('currency', $payload);
        $this->assertArrayNotHasKey('lang', $payload);
    }

    public function testDeliveryPointOmitsToLocation(): void
    {
        $result = $this->builder->buildFromAvr($this->baseAvr([
            'recipient' => [
                'delivery_mode' => 'pvz',
                'pvz_code' => 'AST1',
                'street' => '',
                'building' => '',
            ],
        ]), [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
        ]);

        $this->assertTrue($result['ok'], $result['error']['message'] ?? '');
        $this->assertSame('AST1', $result['payload']['delivery_point']);
        $this->assertArrayNotHasKey('to_location', $result['payload']);
    }

    public function testShipmentPointOmitsFromLocation(): void
    {
        $result = $this->builder->buildFromAvr($this->baseAvr([
            'sender' => [
                'shipment_point' => 'ALA9',
                'street' => '',
                'building' => '',
            ],
        ]), [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
        ]);

        $this->assertTrue($result['ok'], $result['error']['message'] ?? '');
        $this->assertSame('ALA9', $result['payload']['shipment_point']);
        $this->assertArrayNotHasKey('from_location', $result['payload']);
    }

    public function testRejectsFakePhonePlaceholder(): void
    {
        $result = $this->builder->buildFromAvr($this->baseAvr([
            'sender' => ['phone' => '+70000000000'],
        ]), [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_ORIGIN, $result['error']['internal_code']);
    }

    public function testRequiresAddressForDoorDelivery(): void
    {
        $result = $this->builder->buildFromAvr($this->baseAvr([
            'recipient' => [
                'street' => '',
                'building' => '',
                'apartment' => '',
            ],
        ]), [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_DESTINATION, $result['error']['internal_code']);
    }

    public function testRejectsMissingTariff(): void
    {
        $result = $this->builder->buildFromAvr($this->baseAvr([
            'service' => ['service_code' => 'stub_x'],
        ]), [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testIdenticalPayloadSameHash(): void
    {
        $a = $this->builder->buildFromAvr($this->baseAvr(), ['from_city_code' => 1, 'to_city_code' => 2]);
        $b = $this->builder->buildFromAvr($this->baseAvr(), ['from_city_code' => 1, 'to_city_code' => 2]);
        $this->assertTrue($a['ok'] && $b['ok']);
        $this->assertSame($a['payload_hash'], $b['payload_hash']);
    }
}
