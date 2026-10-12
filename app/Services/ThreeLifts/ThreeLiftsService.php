<?php

declare(strict_types=1);

namespace App\Services\ThreeLifts;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\Setting;
use PDO;
use PDOException;
use Throwable;

/**
 * Переключатель «3 Поднятия» в таблице settings.
 * Заказы, слоты и sort_at здесь не создаются и не удаляются.
 */
final class ThreeLiftsService
{
    private Setting $settings;
    private PDO $db;

    public function __construct(?Setting $settings = null)
    {
        $this->settings = $settings ?? new Setting();
        $this->db = Database::connect();
    }

    public function isEnabled(): bool
    {
        try {
            $this->settings->forget(ThreeLiftsPolicy::SETTING_KEY);

            return ThreeLiftsPolicy::resolveEnabled($this->settings->getBool(ThreeLiftsPolicy::SETTING_KEY, false));
        } catch (Throwable) {
            return ThreeLiftsPolicy::resolveEnabled(null, true);
        }
    }

    /**
     * Серверная проверка новой покупки. Обход через тело запроса не включает услугу.
     *
     * @param array<string, mixed> $input
     */
    public function guardNewPurchase(array $input = []): ?string
    {
        return ThreeLiftsPolicy::newPurchaseBlock($this->isEnabled(), $input);
    }

    /**
     * @return array{ok: bool, unchanged?: bool, error_code?: string}
     */
    public function setEnabled(bool $enabled, ?bool $expected): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!ThreeLiftsPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'three_lifts.feature_toggle_denied',
                '«3 Поднятия» не переключены: нет права администратора',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        try {
            $this->db->beginTransaction();
            $locked = $this->lockCurrent();
            $current = $locked['enabled'];
            $decision = ThreeLiftsPolicy::decideToggle($current, $enabled, $expected);
            if (!$decision['write']) {
                $this->db->commit();
                if ($decision['unchanged']) {
                    return ['ok' => true, 'unchanged' => true];
                }
                ActivityLogger::warning(
                    'three_lifts.feature_toggle_conflict',
                    '«3 Поднятия» не переключены: состояние уже изменилось',
                    'settings',
                    null,
                    ['actor_id' => $actorId, 'expected' => $expected, 'current' => $current]
                );

                return ['ok' => false, 'error_code' => 'conflict'];
            }

            if (!$this->writeLocked($enabled, $locked['raw'])) {
                $this->db->rollBack();
                ActivityLogger::warning(
                    'three_lifts.feature_toggle_conflict',
                    '«3 Поднятия» не переключены: запись настройки разошлась',
                    'settings',
                    null,
                    ['actor_id' => $actorId]
                );

                return ['ok' => false, 'error_code' => 'conflict'];
            }
            $this->settings->forget(ThreeLiftsPolicy::SETTING_KEY);
            $audit = ThreeLiftsPolicy::toggleAudit($actorId, $current, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? '«3 Поднятия» включены' : '«3 Поднятия» выключены',
                'settings',
                null,
                $audit['context']
            );
            $this->db->commit();

            return ['ok' => true, 'unchanged' => false];
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            ActivityLogger::warning(
                'three_lifts.feature_toggle_failed',
                'Не удалось переключить «3 Поднятия»',
                'settings',
                null
            );

            return ['ok' => false, 'error_code' => 'save_failed'];
        }
    }

    /**
     * @return array{enabled: bool, raw: ?string}
     */
    private function lockCurrent(): array
    {
        $stmt = $this->db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([ThreeLiftsPolicy::SETTING_KEY]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['enabled' => false, 'raw' => null];
        }
        $raw = (string) ($row['value'] ?? '');

        return ['enabled' => ThreeLiftsPolicy::resolveEnabled($raw), 'raw' => $raw];
    }

    private function writeLocked(bool $enabled, ?string $raw): bool
    {
        $value = $enabled ? '1' : '0';
        if ($raw !== null) {
            $update = $this->db->prepare(
                'UPDATE settings SET `value` = ? WHERE `key` = ? AND `value` = ?'
            );
            $update->execute([$value, ThreeLiftsPolicy::SETTING_KEY, $raw]);

            return $update->rowCount() === 1;
        }

        try {
            $insert = $this->db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
            $insert->execute([ThreeLiftsPolicy::SETTING_KEY, $value]);
        } catch (PDOException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());
            if ($state === '23000') {
                return false;
            }
            throw $e;
        }

        return true;
    }
}
