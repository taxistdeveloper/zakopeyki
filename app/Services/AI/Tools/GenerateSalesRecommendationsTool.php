<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * Рекомендации продавцу на основе своей статистики + спроса.
 */
final class GenerateSalesRecommendationsTool extends AbstractTool
{
    public function __construct(
        private readonly GetSalesStatisticsTool $stats = new GetSalesStatisticsTool(),
        private readonly AnalyzeDemandTool $demand = new AnalyzeDemandTool(),
    ) {
    }

    public function name(): string
    {
        return 'generate_sales_recommendations';
    }

    public function description(): string
    {
        return 'Рекомендации продавцу: что улучшить для более быстрых продаж.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'category' => ['type' => 'string'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function execute(array $input, array $ctx): array
    {
        $stats = $this->stats->execute(['days' => 30], $ctx);
        if (empty($stats['ok'])) {
            return $stats;
        }
        $s = $stats['data'];

        $tips = [];
        if (($s['listings']['active'] ?? 0) === 0) {
            $tips[] = 'У вас нет активных объявлений — создайте лот или опубликуйте черновик через AI.';
        }
        if (($s['total_views'] ?? 0) > 0 && ($s['completed_sales'] ?? 0) === 0) {
            $tips[] = 'Есть просмотры, но нет завершённых продаж за период — проверьте цену и качество фото.';
        }
        if (($s['listings']['active'] ?? 0) > 0 && ($s['total_views'] ?? 0) < (($s['listings']['active'] ?? 1) * 5)) {
            $tips[] = 'Мало просмотров на активные лоты — улучшите заголовок и добавьте 3–5 чётких фото.';
        }

        $demandData = null;
        $q = trim((string) ($input['query'] ?? ''));
        $cat = trim((string) ($input['category'] ?? ''));
        if ($q !== '' || $cat !== '') {
            $d = $this->demand->execute(['query' => $q, 'category' => $cat], $ctx);
            if (!empty($d['ok'])) {
                $demandData = $d['data'];
                foreach ($demandData['recommendations'] ?? [] as $r) {
                    $tips[] = $r;
                }
            }
        }

        foreach ($s['top_listings'] ?? [] as $lot) {
            if (($lot['views'] ?? 0) === 0 && ($lot['status'] ?? '') === 'active') {
                $tips[] = 'Лот «' . $lot['title'] . '» без просмотров — обновите фото или цену.';
                break;
            }
        }

        if ($tips === []) {
            $tips[] = 'Продолжайте: статистика стабильна. Следите за статусами заказов и отзывами.';
        }

        return $this->ok([
            'stats' => $s,
            'demand' => $demandData,
            'recommendations' => array_values(array_unique($tips)),
            'source' => 'mysql.products+orders',
            'data_timestamp' => date('c'),
        ]);
    }
}
