<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\BusinessAiOperation;
use App\Models\BusinessUsage;
use App\Services\BusinessPackageService;
use Throwable;

/**
 * Учёт AI-операций: резерв квоты ↔ задача ↔ commit/release.
 *
 * Политика возврата единицы (VAS-15.2 Q5): освобождается только незакоммиченный резерв
 * при техошибке/таймауте после reserve — это не коммерческий refund покупки.
 * Коммерческий возврат купленных extras не реализован (не утверждён).
 *
 * Генераторы инфографики/примерки в репозитории отсутствуют — без executor операция
 * завершается feature_not_implemented без списания (и без фиктивного success).
 */
final class BusinessAiOperationService
{
    private BusinessPackageService $packages;
    private BusinessAiOperation $operations;
    private BusinessUsage $usage;

    public function __construct(
        ?BusinessPackageService $packages = null,
        ?BusinessAiOperation $operations = null,
        ?BusinessUsage $usage = null
    ) {
        $this->packages = $packages ?? new BusinessPackageService();
        $this->operations = $operations ?? new BusinessAiOperation();
        $this->usage = $usage ?? new BusinessUsage();
    }

    /**
     * @param callable|null $executor fn(): array{ok: bool, result?: array, error?: string, code?: string}
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   code?: string,
     *   operation_id?: int,
     *   operation_key?: string,
     *   status?: string,
     *   idempotent?: bool,
     *   remaining?: int,
     *   result?: array
     * }
     */
    public function run(int $userId, string $feature, ?string $operationKey = null, ?callable $executor = null): array
    {
        if (!in_array($feature, [
            BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC,
            BusinessPackagePolicy::FEATURE_AI_TRYON,
            BusinessPackagePolicy::FEATURE_AI_SEO,
        ], true)) {
            return ['ok' => false, 'code' => 'unknown_feature', 'error' => t('business.err_generic')];
        }

        $key = $this->normalizeOperationKey($operationKey, $userId, $feature);
        $existing = $this->operations->findByOperationKey($key);
        if ($existing) {
            return $this->replayExisting($existing);
        }

        $gate = $this->packages->assertFeature($userId, $feature);
        if (!$gate['ok']) {
            return $gate;
        }

        // Нет исполняемого генератора и нет тестового executor — не резервируем квоту.
        if ($executor === null && BusinessPackagePolicy::isUnimplementedGenerator($feature)) {
            $period = date('Y-m');
            $opId = $this->operations->markFailedNoReserve($userId, $feature, $key, $period, 'feature_not_implemented');

            return [
                'ok' => false,
                'code' => 'feature_not_implemented',
                'error' => t('business.err_feature_not_implemented'),
                'operation_id' => $opId,
                'operation_key' => $key,
                'status' => BusinessPackagePolicy::OP_FAILED,
            ];
        }

        $metric = $this->metricForFeature($feature);
        if ($metric === null) {
            return ['ok' => false, 'code' => 'unknown_feature', 'error' => t('business.err_generic')];
        }

        $limits = $gate['limits'] ?? $this->packages->effectiveLimits($userId);
        $cap = (int) ($limits[$this->limitKeyForFeature($feature)] ?? 0);
        $period = date('Y-m');

        $db = Database::connect();
        $lockName = 'bp_ai_' . $userId . '_' . $feature;
        $gotLock = false;
        $opId = 0;
        $reserved = false;

        try {
            $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
            $lockStmt->execute([$lockName]);
            $gotLock = (int) $lockStmt->fetchColumn() === 1;
            if (!$gotLock) {
                return ['ok' => false, 'code' => 'busy', 'error' => t('business.err_busy')];
            }

            $existing = $this->operations->findByOperationKey($key);
            if ($existing) {
                return $this->replayExisting($existing);
            }

            if (!$this->usage->tryReserve($userId, $metric, $period, $cap, 1)) {
                return [
                    'ok' => false,
                    'code' => 'quota_exceeded',
                    'error' => t('business.err_quota', ['left' => '0']),
                ];
            }
            $reserved = true;

            $created = $this->operations->createReserved($userId, $feature, $key, $period);
            if ($created === false) {
                $this->usage->releaseReserve($userId, $metric, $period, 1);
                $reserved = false;
                $race = $this->operations->findByOperationKey($key);
                if ($race) {
                    return $this->replayExisting($race);
                }

                return ['ok' => false, 'code' => 'busy', 'error' => t('business.err_busy')];
            }
            $opId = $created;

            $exec = $executor ?? static function () use ($feature): array {
                if (BusinessPackagePolicy::isUnimplementedGenerator($feature)) {
                    return [
                        'ok' => false,
                        'code' => 'feature_not_implemented',
                        'error' => t('business.err_feature_not_implemented'),
                    ];
                }

                return ['ok' => false, 'code' => 'unknown_feature', 'error' => t('business.err_generic')];
            };

            try {
                $outcome = $exec();
            } catch (Throwable $e) {
                $outcome = [
                    'ok' => false,
                    'code' => 'provider_error',
                    'error' => t('business.err_ai_provider'),
                ];
                ActivityLogger::warning('business.ai_op_exception', 'Исключение AI-операции', 'business_ai_operation', $opId, [
                    'feature' => $feature,
                    'message' => $e->getMessage(),
                ]);
            }

            if (!empty($outcome['ok'])) {
                $this->operations->markSucceeded($opId, is_array($outcome['result'] ?? null) ? $outcome['result'] : null);
                $used = $this->usage->get($userId, $metric, $period);
                ActivityLogger::info('business.ai_op_succeeded', 'AI-операция выполнена', 'business_ai_operation', $opId, [
                    'feature' => $feature,
                    'user_id' => $userId,
                ]);

                return [
                    'ok' => true,
                    'operation_id' => $opId,
                    'operation_key' => $key,
                    'status' => BusinessPackagePolicy::OP_SUCCEEDED,
                    'remaining' => max(0, $cap - $used),
                    'result' => is_array($outcome['result'] ?? null) ? $outcome['result'] : [],
                ];
            }

            $code = (string) ($outcome['code'] ?? 'provider_error');
            $reason = match ($code) {
                'feature_not_implemented' => 'not_implemented',
                'timeout' => 'timeout',
                default => 'provider_error',
            };
            $this->operations->markReleased($opId, $reason, $code);
            $this->usage->releaseReserve($userId, $metric, $period, 1);
            $reserved = false;

            return [
                'ok' => false,
                'code' => $code !== '' ? $code : 'provider_error',
                'error' => (string) ($outcome['error'] ?? t('business.err_ai_provider')),
                'operation_id' => $opId,
                'operation_key' => $key,
                'status' => BusinessPackagePolicy::OP_RELEASED,
            ];
        } catch (Throwable $e) {
            if ($reserved && $opId > 0) {
                try {
                    $this->operations->markReleased($opId, 'system_error', 'system_error');
                    $this->usage->releaseReserve($userId, $metric, $period, 1);
                } catch (Throwable) {
                    // best-effort
                }
            }
            ActivityLogger::warning('business.ai_op_failed', 'Сбой AI-операции', 'business_ai_operation', $opId ?: null, [
                'feature' => $feature,
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'code' => 'system_error', 'error' => t('business.err_generic')];
        } finally {
            if ($gotLock) {
                $unlock = $db->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$lockName]);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replayExisting(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $opId = (int) ($row['id'] ?? 0);
        $key = (string) ($row['operation_key'] ?? '');

        if ($status === BusinessPackagePolicy::OP_SUCCEEDED) {
            $result = [];
            if (!empty($row['result_json'])) {
                $decoded = json_decode((string) $row['result_json'], true);
                $result = is_array($decoded) ? $decoded : [];
            }

            return [
                'ok' => true,
                'idempotent' => true,
                'operation_id' => $opId,
                'operation_key' => $key,
                'status' => $status,
                'result' => $result,
            ];
        }

        if ($status === BusinessPackagePolicy::OP_RESERVED) {
            return [
                'ok' => false,
                'code' => 'operation_in_progress',
                'error' => t('business.err_ai_in_progress'),
                'operation_id' => $opId,
                'operation_key' => $key,
                'status' => $status,
                'idempotent' => true,
            ];
        }

        $code = (string) ($row['error_code'] ?? 'provider_error');

        return [
            'ok' => false,
            'code' => $code !== '' ? $code : 'provider_error',
            'error' => $code === 'feature_not_implemented'
                ? t('business.err_feature_not_implemented')
                : t('business.err_ai_provider'),
            'operation_id' => $opId,
            'operation_key' => $key,
            'status' => $status,
            'idempotent' => true,
        ];
    }

    private function metricForFeature(string $feature): ?string
    {
        return match ($feature) {
            BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC => BusinessPackageService::METRIC_AI_INFOGRAPHIC,
            BusinessPackagePolicy::FEATURE_AI_TRYON => BusinessPackageService::METRIC_AI_TRYON,
            BusinessPackagePolicy::FEATURE_AI_SEO => BusinessPackageService::METRIC_AI_COPY,
            default => null,
        };
    }

    private function limitKeyForFeature(string $feature): string
    {
        return match ($feature) {
            BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC => 'ai_infographic',
            BusinessPackagePolicy::FEATURE_AI_TRYON => 'ai_tryon',
            BusinessPackagePolicy::FEATURE_AI_SEO => 'ai_copy',
            default => $feature,
        };
    }

    private function normalizeOperationKey(?string $key, int $userId, string $feature): string
    {
        $key = trim((string) $key);
        if ($key !== '' && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            return $key;
        }

        return sprintf('ai-%s-%d-%s', $feature, $userId, bin2hex(random_bytes(8)));
    }
}
