<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

/**
 * Genlook Try-On API v1 (https://genlook.app/docs/tryon-api/introduction, проверено 2026-11-09).
 *  - POST   /try-on                      multipart: data(JSON) + файлы person, garment
 *  - GET    /generations/{id}            PENDING|PROCESSING|COMPLETED|FAILED
 *  - DELETE /customers/{externalUserId}  удаление всех изображений пользователя (204)
 */
final class GenlookProvider implements TryOnProviderInterface
{
    private const RETRYABLE_HTTP = [0, 408, 425, 429, 500, 502, 503, 504];

    private ProviderHttp $http;

    /** @param array<string, mixed> $cfg config/tryon.php → providers.genlook */
    public function __construct(private readonly array $cfg, ?ProviderHttp $http = null)
    {
        $this->http = $http ?? new ProviderHttp(null, (int) ($cfg['timeout'] ?? 60));
    }

    public function id(): string
    {
        return 'genlook';
    }

    public function isTest(): bool
    {
        return !empty($this->cfg['mock']);
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->cfg['api_key'] ?? '')) !== '';
    }

    public function supports(string $tryonType): bool
    {
        return in_array($tryonType, (array) ($this->cfg['types'] ?? []), true);
    }

    public function estimateCostMicros(string $tryonType): int
    {
        return $this->isTest() ? 0 : (int) ($this->cfg['cost_micros'] ?? 40000);
    }

    public function submit(TryOnSubmission $s): ProviderJobState
    {
        $data = [
            'products' => [[
                'title' => mb_substr($s->garmentTitle, 0, 200),
                'images' => [['source' => ['fileKey' => 'garment']]],
            ]],
            'person' => ['image' => ['source' => ['fileKey' => 'person']]],
            'externalUserId' => $s->externalUserId,
            'mock' => $this->isTest(),
            'output' => [
                'resolution' => 'default',
                'keepForDays' => (int) ($this->cfg['keep_for_days'] ?? 1),
                'watermark' => false,
            ],
        ];
        $body = [
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'person' => new \CURLFile($s->personJpegPath, 'image/jpeg', 'person.jpg'),
            'garment' => new \CURLFile($s->garmentImagePath, self::mimeOf($s->garmentImagePath), 'garment' . self::extOf($s->garmentImagePath)),
        ];

        [$status, $raw] = $this->http->request('POST', $this->url('/try-on'), $body, $this->headers());
        $json = self::decode($raw);
        if ($status === 201 || $status === 200) {
            $id = (string) ($json['generationId'] ?? '');
            if ($id === '') {
                throw new ProviderException('provider_error', true, $status);
            }

            return new ProviderJobState(ProviderJobState::PENDING, $id);
        }

        throw self::mapHttpError($status, (string) ($json['code'] ?? ''));
    }

    public function status(string $externalId): ProviderJobState
    {
        [$status, $raw] = $this->http->request(
            'GET',
            $this->url('/generations/' . rawurlencode($externalId)),
            null,
            $this->headers()
        );
        if ($status !== 200) {
            throw self::mapHttpError($status, (string) (self::decode($raw)['code'] ?? ''));
        }
        $json = self::decode($raw);

        return match ((string) ($json['status'] ?? '')) {
            'PENDING' => new ProviderJobState(ProviderJobState::PENDING, $externalId),
            'PROCESSING' => new ProviderJobState(ProviderJobState::PROCESSING, $externalId),
            'COMPLETED' => new ProviderJobState(
                ProviderJobState::COMPLETED,
                $externalId,
                (string) ($json['resultImageUrl'] ?? '') ?: null,
                null,
                $this->estimateCostMicros('')
            ),
            'FAILED' => new ProviderJobState(
                ProviderJobState::FAILED,
                $externalId,
                null,
                self::mapAsyncError((string) ($json['errorCode'] ?? '')),
                0
            ),
            default => throw new ProviderException('provider_error', true, $status),
        };
    }

    public function purgeUser(string $externalUserId): bool
    {
        [$status] = $this->http->request(
            'DELETE',
            $this->url('/customers/' . rawurlencode($externalUserId)),
            null,
            $this->headers()
        );

        return $status === 204 || $status === 200;
    }

    public function cancel(string $externalId): bool
    {
        return false;
    }

    /** @return list<string> */
    public function resultHosts(): array
    {
        return array_values((array) ($this->cfg['result_hosts'] ?? []));
    }

    private function url(string $path): string
    {
        return rtrim((string) ($this->cfg['base_url'] ?? 'https://api.genlook.app/tryon/v1'), '/') . $path;
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['x-api-key: ' . (string) ($this->cfg['api_key'] ?? ''), 'Accept: application/json'];
    }

    public static function mapHttpError(int $status, string $code): ProviderException
    {
        if ($status === 402 || $code === 'INSUFFICIENT_CREDITS') {
            return new ProviderException('provider_out_of_credits', false, $status);
        }
        if ($status === 401 || $status === 403) {
            return new ProviderException('provider_auth_failed', false, $status);
        }
        if ($status === 429 || $code === 'RATE_LIMITED') {
            return new ProviderException('provider_busy', true, $status);
        }
        if (in_array($status, self::RETRYABLE_HTTP, true)) {
            return new ProviderException('provider_error', true, $status);
        }
        if ($status === 404) {
            return new ProviderException('provider_not_found', false, $status);
        }
        if ($status >= 400 && $status < 500) {
            return new ProviderException('provider_rejected_image', false, $status);
        }

        return new ProviderException('provider_error', true, $status);
    }

    public static function mapAsyncError(string $code): string
    {
        return match ($code) {
            'GENERATION_TIMEOUT' => 'provider_timeout',
            'MULTI_PRODUCT_NOT_SUPPORTED', 'MISSING_REQUIRED_CLASSIFICATION' => 'product_not_supported',
            default => 'generation_failed',
        };
    }

    /** @return array<string, mixed> */
    private static function decode(string $raw): array
    {
        $j = json_decode($raw, true);

        return is_array($j) ? $j : [];
    }

    private static function mimeOf(string $path): string
    {
        $info = @getimagesize($path);

        return is_array($info) && isset($info['mime']) ? (string) $info['mime'] : 'image/jpeg';
    }

    private static function extOf(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? '.' . $ext : '.jpg';
    }
}
