<?php

namespace App\Services\Delivery;

class StubLogisticsProvider implements LogisticsProviderInterface
{
    public function getQuotes(array $context): array
    {
        $shipment = $context['shipment'] ?? [];
        $recipient = $context['recipient'] ?? [];
        $weight = (float) ($shipment['weight_value'] ?? 1);
        if ($weight <= 0) {
            $weight = 1;
        }

        $packagingAmount = 0;
        if (!empty($shipment['packaging_id'])) {
            $packagingAmount = (int) ($context['packaging_price'] ?? 0);
        }

        $base = (int) max(500, round($weight * 350));
        $validUntil = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $requestHash = hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE));

        $mode = $recipient['delivery_mode'] ?? 'courier';
        $quotes = [];

        if ($mode === 'pvz') {
            $quotes[] = $this->quoteRow('pvz_std', 'ПВЗ стандарт', $base, $packagingAmount, $validUntil, $weight, $requestHash, 3, 7);
        } else {
            $quotes[] = $this->quoteRow('courier_std', 'Курьер стандарт', $base + 300, $packagingAmount, $validUntil, $weight, $requestHash, 2, 5);
            $quotes[] = $this->quoteRow('express', 'Экспресс', $base + 900, $packagingAmount, $validUntil, $weight, $requestHash, 1, 2);
        }

        return $quotes;
    }

    private function quoteRow(
        string $code,
        string $name,
        int $base,
        int $packaging,
        string $validUntil,
        float $weight,
        string $requestHash,
        int $etaMin,
        int $etaMax
    ): array {
        $total = $base + $packaging;
        return [
            'service_code' => $code,
            'service_name' => $name,
            'base_amount' => $base,
            'packaging_amount' => $packaging,
            'extra_services_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'currency' => 'KZT',
            'billable_weight' => $weight,
            'billable_weight_method' => 'max(actual, dim)',
            'eta_days_min' => $etaMin,
            'eta_days_max' => $etaMax,
            'valid_until' => $validUntil,
            'request_payload_hash' => $requestHash,
            'response_hash' => hash('sha256', $code . $total),
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
