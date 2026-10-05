<?php

namespace App\Services\Cdek;

/**
 * Типизированный фасад CDEK API v2 над единым Client.
 *
 * Бизнес-сервисы Zakopeyki должны обращаться сюда (или к Client::call),
 * а не открывать собственные HTTP-соединения к CDEK.
 *
 * На этом этапе методы готовы к использованию; полный delivery business-flow
 * (оплата → create) остаётся на следующих этапах.
 *
 * @see openapi_api_v2_integration.json
 * @see docs/cdek-api-client.md
 */
class CdekApi
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * @return array{ok: bool, token?: string, error?: string, source?: string}
     */
    public function getAccessToken(bool $force = false): array
    {
        return $this->client->authorize($force);
    }

    // ─── Location ───────────────────────────────────────────────

    /** GET /v2/location/suggest/cities */
    public function suggestCities(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/location/suggest/cities', null, $query);
    }

    /** GET /v2/location/regions */
    public function regions(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/location/regions', null, $query);
    }

    /** GET /v2/location/postalcodes */
    public function postalCodes(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/location/postalcodes', null, $query);
    }

    /** GET /v2/location/coordinates */
    public function coordinates(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/location/coordinates', null, $query);
    }

    /** GET /v2/location/cities */
    public function cities(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/location/cities', null, $query);
    }

    /**
     * Convenience: resolve city → code.
     * @return array{code: int, city: string, country_code: string}|null
     */
    public function findCityCode(string $city, ?string $countryCode = null): ?array
    {
        return $this->client->findCityCode($city, $countryCode);
    }

    // ─── Delivery points ────────────────────────────────────────

    /** GET /v2/deliverypoints */
    public function deliveryPoints(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/deliverypoints', null, $query);
    }

    /**
     * GET /v2/deliverypoints/byPolygons — НЕ описан в предоставленном openapi_api_v2_integration.json.
     * Метод зарезервирован; вызов вернёт структурированную ошибку GAP.
     *
     * @param array<string, mixed> $body
     */
    public function deliveryPointsByPolygons(array $body): CdekApiResponse
    {
        return CdekApiResponse::fromClientResult([
            'ok' => false,
            'code' => 0,
            'error' => 'CDEK endpoint /deliverypoints/byPolygons is not present in provided OpenAPI (GAP)',
            'error_mapped' => $this->client->errorMapper()->mapLocal(
                CdekErrorMapper::INTERNAL_UNKNOWN,
                'Endpoint /deliverypoints/byPolygons not in OpenAPI — GAP'
            ),
            'request_id' => 'gap-byPolygons',
            'duration_ms' => 0,
        ], $this->client->errorMapper());
    }

    // ─── Calculator ─────────────────────────────────────────────

    /**
     * POST /v2/calculator/tariff
     * @param array<string, mixed> $body CalculatorRequestDto
     */
    public function calculateTariff(array $body): CdekApiResponse
    {
        return $this->client->call(
            'POST',
            '/calculator/tariff',
            $body,
            null,
            $this->client->developerKeyHeader(),
            ['idempotent' => true, 'allow_retry' => true]
        );
    }

    /**
     * POST /v2/calculator/tariffAndService
     * @param array<string, mixed> $body CalculatorTariffWithServicesRequestDto
     */
    public function calculateTariffAndService(array $body): CdekApiResponse
    {
        return $this->client->call(
            'POST',
            '/calculator/tariffAndService',
            $body,
            null,
            $this->client->developerKeyHeader(),
            ['idempotent' => true, 'allow_retry' => true]
        );
    }

    /**
     * POST /v2/calculator/tarifflist
     * @param array<string, mixed> $body CalculatorTariffListRequestDto
     */
    public function calculateTariffList(array $body): CdekApiResponse
    {
        return $this->client->call(
            'POST',
            '/calculator/tarifflist',
            $body,
            null,
            $this->client->developerKeyHeader(),
            ['idempotent' => true, 'allow_retry' => true]
        );
    }

    /** GET /v2/calculator/alltariffs */
    public function allTariffs(?string $lang = null): CdekApiResponse
    {
        $headers = $this->client->developerKeyHeader();
        if ($lang !== null && $lang !== '') {
            $headers[] = 'X-User-Lang: ' . $lang;
        }
        return $this->client->call('GET', '/calculator/alltariffs', null, null, $headers);
    }

    // ─── Orders ─────────────────────────────────────────────────

    /** GET /v2/orders?cdek_number=&im_number= */
    public function getOrders(array $query = []): CdekApiResponse
    {
        return $this->client->call('GET', '/orders', null, $query);
    }

    /**
     * POST /v2/orders — регистрация заказа.
     * HTTP 202 → internal_status=accepted (НЕ final success).
     * Автоматический retry создания ОТКЛЮЧЁН.
     *
     * @param array<string, mixed> $body OrderCreateRequestDto
     */
    public function createOrder(array $body, ?string $developerKey = null): CdekApiResponse
    {
        return $this->client->call(
            'POST',
            '/orders',
            $body,
            null,
            $this->client->developerKeyHeader($developerKey),
            ['idempotent' => false, 'allow_retry' => false]
        );
    }

    /**
     * PATCH /v2/orders
     * @param array<string, mixed> $body OrderUpdateRequestDto
     */
    public function updateOrder(array $body, ?string $developerKey = null): CdekApiResponse
    {
        return $this->client->call(
            'PATCH',
            '/orders',
            $body,
            null,
            $this->client->developerKeyHeader($developerKey),
            ['idempotent' => false, 'allow_retry' => false]
        );
    }

    /** GET /v2/orders/{uuid} */
    public function getOrder(string $uuid): CdekApiResponse
    {
        return $this->client->call('GET', '/orders/' . rawurlencode($uuid));
    }

    /** DELETE /v2/orders/{uuid} */
    public function deleteOrder(string $uuid): CdekApiResponse
    {
        return $this->client->call(
            'DELETE',
            '/orders/' . rawurlencode($uuid),
            null,
            null,
            [],
            ['idempotent' => false, 'allow_retry' => false]
        );
    }

    // ─── Print ──────────────────────────────────────────────────

    /**
     * POST /v2/print/orders
     * @param array<string, mixed> $body WaybillRequestDto
     */
    public function printOrders(array $body): CdekApiResponse
    {
        return $this->client->call(
            'POST',
            '/print/orders',
            $body,
            null,
            [],
            ['idempotent' => false, 'allow_retry' => false]
        );
    }

    /** GET /v2/print/orders/{uuid} */
    public function getPrintOrder(string $uuid): CdekApiResponse
    {
        return $this->client->call('GET', '/print/orders/' . rawurlencode($uuid));
    }

    /** GET /v2/print/orders/{uuid}.pdf */
    public function getPrintOrderPdf(string $uuid): CdekApiResponse
    {
        return $this->client->call('GET', '/print/orders/' . rawurlencode($uuid) . '.pdf');
    }

    // ─── Webhooks (registration only; inbound processing = later stage) ──

    /** GET /v2/webhooks */
    public function listWebhooks(): CdekApiResponse
    {
        return $this->client->call('GET', '/webhooks');
    }

    /**
     * POST /v2/webhooks
     * @param array{type: string, url: string} $body WebhookDto (uuid readOnly)
     */
    public function createWebhook(array $body): CdekApiResponse
    {
        return $this->client->call(
            'POST',
            '/webhooks',
            $body,
            null,
            [],
            ['idempotent' => false, 'allow_retry' => false]
        );
    }

    /** GET /v2/webhooks/{uuid} */
    public function getWebhook(string $uuid): CdekApiResponse
    {
        return $this->client->call('GET', '/webhooks/' . rawurlencode($uuid));
    }

    /** DELETE /v2/webhooks/{uuid} */
    public function deleteWebhook(string $uuid): CdekApiResponse
    {
        return $this->client->call(
            'DELETE',
            '/webhooks/' . rawurlencode($uuid),
            null,
            null,
            [],
            ['idempotent' => false, 'allow_retry' => false]
        );
    }
}
