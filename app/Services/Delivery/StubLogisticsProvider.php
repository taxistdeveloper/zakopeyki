<?php

namespace App\Services\Delivery;

class StubLogisticsProvider implements LogisticsProviderInterface
{
    private const DIM_DIVISOR = 5000.0;
    private const TARIFF_VERSION = 'stub-1';

    public function getQuotes(array $context): array
    {
        $shipment = $context['shipment'] ?? [];
        $recipient = $context['recipient'] ?? [];
        $packReco = new PackagingRecommendationService();

        $gross = (float) ($shipment['gross_weight'] ?? $shipment['weight_value'] ?? 1);
        if ($gross <= 0) {
            $gross = 1.0;
        }

        $length = (float) ($shipment['billed_length'] ?? $shipment['package_length'] ?? $shipment['length_value'] ?? 20);
        $width = (float) ($shipment['billed_width'] ?? $shipment['package_width'] ?? $shipment['width_value'] ?? 20);
        $height = (float) ($shipment['billed_height'] ?? $shipment['package_height'] ?? $shipment['height_value'] ?? 10);

        $dimWeight = $packReco->dimWeight($length, $width, $height, self::DIM_DIVISOR);
        $billable = $packReco->billableWeight($gross, $dimWeight, 'max');

        $packagingAmount = (int) ($context['packaging_price'] ?? 0);
        $handling = !empty($shipment['is_irregular']) ? 500 : 0;
        $surchargeFragile = !empty($shipment['is_fragile']) ? 300 : 0;

        $base = (int) max(500, round($billable * 350));
        $validUntil = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $requestHash = hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE));

        $mode = $recipient['delivery_mode'] ?? 'courier';
        $quotes = [];

        $meta = [
            'billable_weight' => $billable,
            'gross_weight' => $gross,
            'dim_weight' => round($dimWeight, 3),
            'dim_divisor' => self::DIM_DIVISOR,
        ];

        if ($mode === 'pvz') {
            $quotes[] = $this->quoteRow(
                'pvz_std',
                'ПВЗ стандарт',
                $base,
                $packagingAmount,
                $handling,
                $surchargeFragile,
                $validUntil,
                $billable,
                $requestHash,
                3,
                7,
                $meta
            );
        } else {
            $quotes[] = $this->quoteRow(
                'courier_std',
                'Курьер стандарт',
                $base + 300,
                $packagingAmount,
                $handling,
                $surchargeFragile,
                $validUntil,
                $billable,
                $requestHash,
                2,
                5,
                $meta
            );
            $quotes[] = $this->quoteRow(
                'express',
                'Экспресс',
                $base + 900,
                $packagingAmount,
                $handling,
                $surchargeFragile,
                $validUntil,
                $billable,
                $requestHash,
                1,
                2,
                $meta
            );
        }

        return $quotes;
    }

    /** @param array<string, mixed> $meta */
    private function quoteRow(
        string $code,
        string $name,
        int $base,
        int $packaging,
        int $handling,
        int $extras,
        string $validUntil,
        float $billable,
        string $requestHash,
        int $etaMin,
        int $etaMax,
        array $meta
    ): array {
        $extraServices = $extras;
        $total = $base + $packaging + $handling + $extraServices;
        return [
            'service_code' => $code,
            'service_name' => $name,
            'tariff_version' => self::TARIFF_VERSION,
            'base_amount' => $base,
            'packaging_amount' => $packaging,
            'handling_amount' => $handling,
            'extra_services_amount' => $extraServices,
            'discount_amount' => 0,
            'total_amount' => $total,
            'currency' => 'KZT',
            'billable_weight' => $billable,
            'billable_weight_method' => 'max(gross, dim)',
            'calculation_method' => 'stub_tariff_v1',
            'eta_days_min' => $etaMin,
            'eta_days_max' => $etaMax,
            'valid_until' => $validUntil,
            'request_payload_hash' => $requestHash,
            'response_hash' => hash('sha256', $code . $total . json_encode($meta)),
        ];
    }

    public function createOrder(array $context): array
    {
        $deliveryOrderId = (int) ($context['delivery_order_id'] ?? 0);
        return [
            'logistics_order_id' => 'STUB-' . $deliveryOrderId . '-' . strtoupper(bin2hex(random_bytes(3))),
            'tracking_number' => 'TRK' . str_pad((string) $deliveryOrderId, 8, '0', STR_PAD_LEFT),
        ];
    }

    public function handleStatusWebhook(array $payload): ?array
    {
        $deliveryOrderId = (int) ($payload['delivery_order_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');
        if ($deliveryOrderId <= 0 || $status === '') {
            return null;
        }

        return [
            'delivery_order_id' => $deliveryOrderId,
            'status' => $status,
            'tracking_number' => $payload['tracking_number'] ?? null,
            'message' => $payload['message'] ?? null,
            'location' => $payload['location'] ?? null,
        ];
    }
}
