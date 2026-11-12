<?php

declare(strict_types=1);

namespace App\Services\TryOn;

/**
 * Скачивание результата провайдера с защитой от SSRF (docs/tryon/04-storage-security.md §3):
 * только https, хост из allowlist, без редиректов, DNS → только публичные IP (адрес закрепляется
 * через CURLOPT_RESOLVE против DNS-rebinding), лимит размера и времени.
 */
final class TryOnResultFetcher
{
    /** @var callable */
    private $resolver;
    /** @var callable|null */
    private $transport;

    /**
     * @param callable|null $resolver  fn(string $host): list<string> IP-адреса
     * @param callable|null $transport fn(string $url, string $host, string $ip, int $maxBytes, int $timeout): array{0:int,1:string}
     */
    public function __construct(
        private readonly int $maxBytes = 15728640,
        private readonly int $timeout = 20,
        ?callable $resolver = null,
        ?callable $transport = null,
    ) {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = (string) $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = (string) $r['ipv6'];
                }
            }

            return $ips;
        };
        $this->transport = $transport;
    }

    /**
     * @param list<string> $allowedHosts точные хосты или их родительские домены
     * @throws TryOnException result_fetch_failed
     */
    public function fetch(string $url, array $allowedHosts): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new TryOnException('result_fetch_failed', 502, ['reason' => 'scheme']);
        }
        if (!self::hostAllowed($host, $allowedHosts)) {
            throw new TryOnException('result_fetch_failed', 502, ['reason' => 'host']);
        }

        $ips = ($this->resolver)($host);
        $ip = null;
        foreach ($ips as $candidate) {
            if (!self::isPublicIp($candidate)) {
                throw new TryOnException('result_fetch_failed', 502, ['reason' => 'private_ip']);
            }
            $ip ??= $candidate;
        }
        if ($ip === null) {
            throw new TryOnException('result_fetch_failed', 502, ['reason' => 'dns']);
        }

        [$status, $body] = $this->transport !== null
            ? ($this->transport)($url, $host, $ip, $this->maxBytes, $this->timeout)
            : $this->curlGet($url, $host, $ip);

        if ($status !== 200 || $body === '' || strlen($body) > $this->maxBytes) {
            throw new TryOnException('result_fetch_failed', 502, ['reason' => 'http_' . $status]);
        }

        return $body;
    }

    /** @param list<string> $allowed */
    public static function hostAllowed(string $host, array $allowed): bool
    {
        foreach ($allowed as $a) {
            $a = strtolower(trim($a));
            if ($a !== '' && ($host === $a || str_ends_with($host, '.' . $a))) {
                return true;
            }
        }

        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return array{0:int,1:string} */
    private function curlGet(string $url, string $host, string $ip): array
    {
        $buf = '';
        $tooBig = false;
        $max = $this->maxBytes;
        $ipForResolve = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':443:' . $ipForResolve],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf, &$tooBig, $max): int {
                if (strlen($buf) + strlen($chunk) > $max) {
                    $tooBig = true;
                    return 0; // прерывает передачу
                }
                $buf .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$tooBig ? 413 : $status, $tooBig ? '' : $buf];
    }
}
