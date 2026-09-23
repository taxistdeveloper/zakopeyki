<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Helpers\ProductHelper;
use App\Models\Favorite;

final class GetFavoritesTool extends AbstractTool
{
    public function __construct(private readonly Favorite $favorites = new Favorite())
    {
    }

    public function name(): string
    {
        return 'get_favorites';
    }

    public function description(): string
    {
        return 'Список избранных объявлений пользователя.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function execute(array $input, array $ctx): array
    {
        $userId = (int) ($ctx['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->fail('Войдите в аккаунт, чтобы открыть избранное.');
        }

        $limit = max(1, min(24, (int) ($input['limit'] ?? 12)));
        $rows = array_slice($this->favorites->forUser($userId), 0, $limit);

        $serialized = array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'price' => ProductHelper::formatPrice($item),
                'url' => ProductHelper::url('/product/' . (int) $item['id']),
                'image' => ProductHelper::imageUrl($item),
                'location' => (string) ($item['location'] ?? ''),
            ];
        }, $rows);

        return $this->ok([
            'products' => $serialized,
            'count' => count($serialized),
            'source' => 'mysql.favorites',
            'data_timestamp' => date('c'),
        ]);
    }
}
