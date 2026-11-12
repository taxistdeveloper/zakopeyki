<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

/**
 * Минимальный HTTP-клиент на curl с инжектируемым транспортом для тестов
 * (по образцу App\Services\Cdek\Client): fn(string $method, string $url, mixed $body, array $headers): array{0:int,1:string}
 */
final class ProviderHttp
{
    /** @var callable|null */
    private $transport;

    public function __construct(?callable $transport = null, private readonly int $timeout = 60)
    {
        $this->transport = $transport;
    }

    /**
     * @param string|array<string, mixed>|null $body string — как есть; array — multipart (CURLFile допустим)
     * @param list<string> $headers
     * @return array{0:int,1:string} [status, body]; status 0 — сетевая ошибка
     */
    public function request(string $method, string $url, string|array|null $body, array $headers): array
    {
        if (is_callable($this->transport)) {
            $r = ($this->transport)($method, $url, $body, $headers);

            return [(int) ($r[0] ?? 0), (string) ($r[1] ?? '')];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) {
            return [0, ''];
        }

        return [$status, (string) $resp];
    }
}
