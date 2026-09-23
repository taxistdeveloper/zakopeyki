<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Core\Database;
use App\Models\Product;
use PDO;

/**
 * Статистика продавца по своим объявлениям и заказам (реальные данные MySQL).
 */
final class GetSalesStatisticsTool extends AbstractTool
{
    public function __construct(
        private readonly Product $products = new Product(),
        private readonly ?PDO $pdo = null,
    ) {
    }

    public function name(): string
    {
        return 'get_sales_statistics';
    }

    public function description(): string
    {
        return 'Статистика продаж и объявлений текущего продавца.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['days' => ['type' => 'integer']]];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function execute(array $input, array $ctx): array
    {
        $userId = (int) ($ctx['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->fail('Войдите как продавец, чтобы увидеть статистику.');
        }

        $days = max(7, min(365, (int) ($input['days'] ?? 30)));
        $pdo = $this->pdo ?? Database::connect();

        $active = $this->countProducts($pdo, $userId, 'active');
        $sold = $this->countProducts($pdo, $userId, 'sold');
        $archived = $this->countProducts($pdo, $userId, 'archived');

        $orders = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
             FROM orders WHERE seller_id = ? AND created_at >= (NOW() - INTERVAL {$days} DAY)
             GROUP BY status"
        );
        $orders->execute([$userId]);
        $byStatus = [];
        $gmv = 0;
        $completed = 0;
        foreach ($orders->fetchAll() ?: [] as $row) {
            $byStatus[(string) $row['status']] = [
                'count' => (int) $row['cnt'],
                'amount' => (int) $row['total'],
            ];
            if (($row['status'] ?? '') === 'completed') {
                $completed = (int) $row['cnt'];
                $gmv = (int) $row['total'];
            }
        }

        $views = 0;
        try {
            $v = $pdo->prepare(
                "SELECT COALESCE(SUM(p.view_count),0) FROM products p WHERE p.user_id = ?"
            );
            $v->execute([$userId]);
            $views = (int) $v->fetchColumn();
        } catch (\Throwable) {
        }

        $top = $pdo->prepare(
            "SELECT id, title, price, status, view_count, created_at
             FROM products WHERE user_id = ? AND type <> 'course'
             ORDER BY view_count DESC, created_at DESC LIMIT 5"
        );
        $top->execute([$userId]);
        $topListings = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'price' => (int) $r['price'],
                'status' => (string) $r['status'],
                'views' => (int) ($r['view_count'] ?? 0),
            ];
        }, $top->fetchAll() ?: []);

        return $this->ok([
            'period_days' => $days,
            'listings' => [
                'active' => $active,
                'sold' => $sold,
                'archived' => $archived,
            ],
            'orders_by_status' => $byStatus,
            'completed_sales' => $completed,
            'gmv_completed' => $gmv,
            'total_views' => $views,
            'top_listings' => $topListings,
            'source' => 'mysql.products+orders',
            'data_timestamp' => date('c'),
            'note' => 'GMV — сумма завершённых заказов (completed), не заявочные цены объявлений.',
        ]);
    }

    private function countProducts(PDO $pdo, int $userId, string $status): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM products WHERE user_id = ? AND status = ? AND type <> 'course'"
        );
        $stmt->execute([$userId, $status]);
        return (int) $stmt->fetchColumn();
    }
}
