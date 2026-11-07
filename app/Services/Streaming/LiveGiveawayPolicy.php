<?php

declare(strict_types=1);

namespace App\Services\Streaming;

use App\Models\Setting;

/**
 * Выключатель розыгрыша в прямом эфире.
 *
 * Механика — случайное определение победителя. До получения юридического заключения
 * (MASTERING-01, раздел K) по умолчанию ВЫКЛЮЧЕНА; включается админом в /admin/stream.
 */
final class LiveGiveawayPolicy
{
    public const SETTING_KEY = 'live_giveaway_enabled';

    public static function isEnabled(): bool
    {
        try {
            return (new Setting())->getBool(self::SETTING_KEY) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
