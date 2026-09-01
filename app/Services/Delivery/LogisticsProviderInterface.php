<?php

namespace App\Services\Delivery;

interface LogisticsProviderInterface
{
    /**
     * @param array<string, mixed> $context sender, recipient, shipment
     * @return array<int, array<string, mixed>> quote rows for DeliveryOrder::saveQuotes
     */
    public function getQuotes(array $context): array;

    /**
     * @param array<string, mixed> $context full AVR package
     * @return array{logistics_order_id: string, tracking_number?: string|null}
     */
    public function createOrder(array $context): array;

    /**
     * @param array<string, mixed> $payload webhook body
     */
    public function handleStatusWebhook(array $payload): ?array;
}
