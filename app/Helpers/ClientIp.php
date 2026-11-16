<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * M-01 / B0.8: определение IP клиента без доверия к подделываемым заголовкам.
 *
 * - CF-Connecting-IP учитывается только если соединение пришло с адреса Cloudflare.
 * - X-Forwarded-For учитывается только если соединение пришло с доверенного прокси
 *   (loopback или APP_TRUSTED_PROXIES — список IP/CIDR через запятую);
 *   берётся самый правый адрес, не являющийся доверенным прокси.
 * - Иначе — REMOTE_ADDR.
 */
final class ClientIp
{
    /** Опубликованные диапазоны Cloudflare (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    /**
     * @param array<string, mixed>|null $server для тестов; по умолчанию $_SERVER
     * @param list<string>|null $trustedProxies для тестов; по умолчанию из APP_TRUSTED_PROXIES
     */
    public static function resolve(?array $server = null, ?array $trustedProxies = null): ?string
    {
        $server ??= $_SERVER;
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return null;
        }

        $trusted = array_merge(self::LOOPBACK, $trustedProxies ?? self::configuredProxies());

        $cf = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && self::inRanges($remote, self::CLOUDFLARE_RANGES)) {
            return $cf;
        }

        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '' && self::inRanges($remote, $trusted)) {
            $hops = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    break;
                }
                if (!self::inRanges($hop, $trusted)) {
                    return $hop;
                }
            }
        }

        return $remote;
    }

    /** @return list<string> */
    private static function configuredProxies(): array
    {
        $raw = (string) (getenv('APP_TRUSTED_PROXIES') ?: '');
        $list = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /** @param list<string> $ranges */
    public static function inRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
