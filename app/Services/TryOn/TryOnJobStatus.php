<?php

declare(strict_types=1);

namespace App\Services\TryOn;

/**
 * Статусы задания и разрешённые переходы (docs/tryon/01-architecture.md §3).
 */
final class TryOnJobStatus
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::QUEUED => [self::PROCESSING, self::CANCELLED, self::FAILED],
        self::PROCESSING => [self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::SUCCEEDED => [self::EXPIRED],
        self::FAILED => [self::EXPIRED],
        self::CANCELLED => [],
        self::EXPIRED => [],
    ];

    public const ACTIVE = [self::QUEUED, self::PROCESSING];
    public const FINAL = [self::SUCCEEDED, self::FAILED, self::CANCELLED, self::EXPIRED];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @param list<string> $from */
    public static function canTransitionAny(array $from, string $to): bool
    {
        if ($from === []) {
            return false;
        }
        foreach ($from as $f) {
            if (!self::canTransition($f, $to)) {
                return false;
            }
        }

        return true;
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }
}
