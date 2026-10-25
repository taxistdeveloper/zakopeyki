<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Core\Database;
use App\Services\BusinessPackageService;
use PDO;

/**
 * Расширенная аналитика продавца — только при активном пакете.
 * Базовые счётчики (view_count) на карточке товара не подменяются.
 */
final class SellerAnalyticsService
{
    private BusinessPackageService $packages;
    private PDO $db;

    public function __construct(
        ?BusinessPackageService $packages = null,
        ?PDO $db = null
    ) {
        $this->packages = $packages ?? new BusinessPackageService();
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{ok: bool, error?: string, code?: string, data?: array<string, mixed>}
     */
    public function extendedSnapshot(int $userId, ?int $days = null): array
    {
        $gate = $this->packages->assertFeature($userId, BusinessPackagePolicy::FEATURE_ANALYTICS_EXTENDED);
        if (!$gate['ok']) {
            return $gate;
        }

        $limits = $gate['limits'] ?? [];
        $maxMonths = max(1, (int) ($limits['analytics_months'] ?? 1));
        $maxDays = min(365, $maxMonths * 30);
        $days = $days === null ? min(30, $maxDays) : max(1, min($days, $maxDays));

        $since = date('Y-m-d H:i:s', time() - ($days * 86400));

        $listings = $this->db->prepare(
            'SELECT COUNT(*) FROM products WHERE user_id = ?'
        );
        $listings->execute([$userId]);
        $listingCount = (int) $listings->fetchColumn();

        $views = $this->db->prepare(
            'SELECT COALESCE(SUM(view_count), 0) FROM products WHERE user_id = ?'
        );
        $views->execute([$userId]);
        $viewSum = (int) $views->fetchColumn();

        $recentViews = 0;
        try {
            $rv = $this->db->prepare(
                'SELECT COUNT(*) FROM product_views pv
                 INNER JOIN products p ON p.id = pv.product_id
                 WHERE p.user_id = ? AND pv.created_at >= ?'
            );
            $rv->execute([$userId, $since]);
            $recentViews = (int) $rv->fetchColumn();
        } catch (\Throwable) {
            $recentViews = 0;
        }

        $salesCount = 0;
        try {
            // orders не имеет updated_at — дата сделки: confirmed_at / released_at / created_at
            $sales = $this->db->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE seller_id = ? AND status IN ('completed', 'confirmed')
                   AND COALESCE(confirmed_at, released_at, created_at) >= ?"
            );
            $sales->execute([$userId, $since]);
            $salesCount = (int) $sales->fetchColumn();
        } catch (\Throwable) {
            $salesCount = 0;
        }

        $favorites = 0;
        try {
            $fav = $this->db->prepare(
                'SELECT COUNT(*) FROM favorites f
                 INNER JOIN products p ON p.id = f.product_id
                 WHERE p.user_id = ?'
            );
            $fav->execute([$userId]);
            $favorites = (int) $fav->fetchColumn();
        } catch (\Throwable) {
            $favorites = 0;
        }

        return [
            'ok' => true,
            'data' => [
                'days' => $days,
                'max_days_allowed' => $maxDays,
                'listings_total' => $listingCount,
                'views_total' => $viewSum,
                'views_period' => $recentViews,
                'sales_completed_period' => $salesCount,
                'favorites_total' => $favorites,
                // Полный состав sec8 (контакты, эффективность промо) — не утверждён / нет данных
                'scope' => 'extended_v1_existing_metrics',
            ],
        ];
    }
}
