<?php

namespace App\Services\Delivery;

/**
 * Рекомендация стандартной упаковки (ТЗ §23).
 * Zakopeyki не определяет тариф — только подбирает допустимую упаковку по габаритам/весу.
 */
class PackagingRecommendationService
{
    private const DEFAULT_PADDING_CM = 2.0;
    private const DEFAULT_PACKAGING_WEIGHT_KG = 0.15;

    /**
     * @param array<int, array<string, mixed>> $packagings каталог логистики
     * @return array{
     *   recommended: ?array,
     *   alternatives: array<int, array>,
     *   fits: array<int, array>,
     *   none_fit: bool
     * }
     */
    public function recommend(array $packagings, ?float $itemWeight, ?float $itemL, ?float $itemW, ?float $itemH): array
    {
        $fits = [];
        foreach ($packagings as $pack) {
            $check = $this->packagingFits($pack, $itemWeight, $itemL, $itemW, $itemH);
            if ($check['fits']) {
                $fits[] = array_merge($pack, ['fit_reason' => $check['reason']]);
            }
        }

        usort($fits, static function (array $a, array $b): int {
            $volA = (float) ($a['length_cm'] ?? 0) * (float) ($a['width_cm'] ?? 0) * (float) ($a['height_cm'] ?? 0);
            $volB = (float) ($b['length_cm'] ?? 0) * (float) ($b['width_cm'] ?? 0) * (float) ($b['height_cm'] ?? 0);
            return $volA <=> $volB;
        });

        $recommended = $fits[0] ?? null;
        $alternatives = array_slice($fits, 1);

        return [
            'recommended' => $recommended,
            'alternatives' => $alternatives,
            'fits' => $fits,
            'none_fit' => $fits === [] && ($itemL !== null || $itemW !== null || $itemH !== null || $itemWeight !== null),
        ];
    }

    /**
     * @param array<string, mixed> $packaging
     * @return array{fits: bool, reason: string}
     */
    public function packagingFits(
        array $packaging,
        ?float $itemWeight,
        ?float $itemL,
        ?float $itemW,
        ?float $itemH
    ): array {
        $maxWeight = (float) ($packaging['max_weight_kg'] ?? 0);
        $padding = (float) ($packaging['padding_cm'] ?? self::DEFAULT_PADDING_CM);

        if ($itemWeight !== null && $itemWeight > 0 && $maxWeight > 0 && $itemWeight > $maxWeight) {
            return ['fits' => false, 'reason' => t('delivery.pack_fit_weight_exceeded')];
        }

        if ($itemL === null || $itemW === null || $itemH === null) {
            if ($itemWeight !== null && $itemWeight > 0) {
                return ['fits' => true, 'reason' => t('delivery.pack_fit_weight_only')];
            }
            return ['fits' => true, 'reason' => t('delivery.pack_fit_unknown_dims')];
        }

        $itemDims = $this->sortedDesc([$itemL, $itemW, $itemH]);
        $required = [
            $itemDims[0] + $padding * 2,
            $itemDims[1] + $padding * 2,
            $itemDims[2] + $padding * 2,
        ];
        $required = $this->sortedDesc($required);

        $boxDims = $this->sortedDesc([
            (float) ($packaging['length_cm'] ?? 0),
            (float) ($packaging['width_cm'] ?? 0),
            (float) ($packaging['height_cm'] ?? 0),
        ]);

        if ($boxDims[0] <= 0 || $boxDims[1] <= 0 || $boxDims[2] <= 0) {
            return ['fits' => true, 'reason' => t('delivery.pack_fit_no_limits')];
        }

        for ($i = 0; $i < 3; $i++) {
            if ($required[$i] > $boxDims[$i]) {
                return ['fits' => false, 'reason' => t('delivery.pack_fit_too_small')];
            }
        }

        return ['fits' => true, 'reason' => t('delivery.pack_fit_ok')];
    }

    /**
     * @param array<string, mixed> $packaging
     * @return array{ok: bool, error?: string}
     */
    public function validateSelection(
        array $packaging,
        ?float $itemWeight,
        ?float $itemL,
        ?float $itemW,
        ?float $itemH
    ): array {
        $check = $this->packagingFits($packaging, $itemWeight, $itemL, $itemW, $itemH);
        if (!$check['fits']) {
            return ['ok' => false, 'error' => t('delivery.packaging_incompatible', ['reason' => $check['reason']])];
        }
        return ['ok' => true];
    }

    public function defaultPackagingWeightKg(): float
    {
        return self::DEFAULT_PACKAGING_WEIGHT_KG;
    }

    /**
     * Округление для тарифа (ТЗ §27): raw сохраняется, billed — для расчёта.
     */
    public function billedDimension(float $raw, string $mode = 'CEIL'): float
    {
        return match (strtoupper($mode)) {
            'CEIL' => ceil($raw),
            'ROUND' => round($raw),
            default => $raw,
        };
    }

    /**
     * DIM weight по правилам тарифа (ТЗ §26). divisor приходит от логистики.
     */
    public function dimWeight(float $lengthCm, float $widthCm, float $heightCm, float $divisor): float
    {
        if ($divisor <= 0) {
            return 0.0;
        }
        return ($lengthCm * $widthCm * $heightCm) / $divisor;
    }

    public function billableWeight(float $grossKg, float $dimKg, string $method = 'max'): float
    {
        return match ($method) {
            'max' => max($grossKg, $dimKg),
            'actual' => $grossKg,
            default => max($grossKg, $dimKg),
        };
    }

    /** @param array<int, float> $values */
    private function sortedDesc(array $values): array
    {
        rsort($values);
        return $values;
    }
}
