<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Core\Database;
use App\Models\Product;
use App\Services\AI\Core\ActionConfirmationService;
use App\Services\AI\Core\AiConfig;
use App\Services\PriceRecommendationService;
use PDO;

/**
 * Черновик объявления через AI. Публикация — только после confirm token.
 */
final class ListingDraftService
{
    private PDO $pdo;
    private Product $products;
    private PriceRecommendationService $prices;
    private ActionConfirmationService $confirmations;

    public function __construct(
        ?Product $products = null,
        ?PriceRecommendationService $prices = null,
        ?ActionConfirmationService $confirmations = null,
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->products = $products ?? new Product();
        $this->prices = $prices ?? new PriceRecommendationService();
        $ttl = (int) AiConfig::get('confirmation_ttl_seconds', 900);
        $this->confirmations = $confirmations ?? new ActionConfirmationService($ttl);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,draft?:array,confirm_token?:string,error?:string}
     */
    public function createDraft(int $userId, array $input, ?int $conversationId = null): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $type = (string) ($input['type'] ?? 'used');
        if (!in_array($type, ['used', 'new', 'free', 'exchange', 'service'], true)) {
            $type = 'used';
        }

        if ($title === '' && (!empty($input['brand']) || !empty($input['model']))) {
            $title = trim(implode(' ', array_filter([
                (string) ($input['brand'] ?? ''),
                (string) ($input['model'] ?? ''),
            ])));
        }

        if ($title === '') {
            return ['ok' => false, 'error' => 'Не хватает названия товара. Уточните, что продаёте.'];
        }

        $missing = [];
        if ($description === '') {
            $description = $this->buildDescription($input);
        }
        if (empty($input['price']) && !in_array($type, ['free', 'exchange'], true)) {
            $missing[] = 'price';
        }
        if (empty($input['location'])) {
            $missing[] = 'location';
        }

        $priceRec = $this->prices->recommend([
            'query' => $title,
            'brand' => $input['brand'] ?? null,
            'model' => $input['model'] ?? null,
            'category' => $input['category'] ?? null,
            'type' => $type,
            'location' => $input['location'] ?? null,
        ]);

        $suggestedPrice = isset($input['price']) ? (int) $input['price'] : ($priceRec['recommended_price'] ?? null);

        $payload = [
            'user_id' => $userId,
            'type' => $type,
            'category' => (string) ($input['category'] ?? 'Разное'),
            'title' => $title,
            'description' => $description,
            'price' => $suggestedPrice ?? 0,
            'location' => (string) ($input['location'] ?? 'Караганда'),
            'whatsapp' => $input['whatsapp'] ?? null,
            'images' => array_values(array_filter((array) ($input['images'] ?? []))),
            'image' => $input['image'] ?? null,
            'tags' => array_values(array_filter((array) ($input['tags'] ?? []))),
            'vision' => $input['vision'] ?? null,
            'price_recommendation' => $priceRec,
            'missing_fields' => $missing,
            'status' => 'draft',
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_listing_drafts (user_id, conversation_id, payload_json, status)
             VALUES (?, ?, ?, \'draft\')'
        );
        $stmt->execute([
            $userId,
            $conversationId,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $draftId = (int) $this->pdo->lastInsertId();
        $payload['draft_id'] = $draftId;

        $token = $this->confirmations->create($userId, 'publish_listing_draft', [
            'draft_id' => $draftId,
        ], $conversationId);

        return [
            'ok' => true,
            'draft' => $payload,
            'confirm_token' => $token,
        ];
    }

    /**
     * @return array{ok:bool,product_id?:int,error?:string}
     */
    public function publishDraft(int $userId, int $draftId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ai_listing_drafts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$draftId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Черновик не найден.'];
        }
        if (($row['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'error' => 'Черновик уже обработан.'];
        }

        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'Повреждённые данные черновика.'];
        }

        if (empty($payload['title']) || empty($payload['description'])) {
            return ['ok' => false, 'error' => 'Нельзя опубликовать: нет названия или описания.'];
        }

        $productId = $this->products->create([
            'user_id' => $userId,
            'type' => $payload['type'] ?? 'used',
            'category' => $payload['category'] ?? 'Разное',
            'title' => $payload['title'],
            'description' => $payload['description'],
            'price' => (int) ($payload['price'] ?? 0),
            'location' => $payload['location'] ?? 'Караганда',
            'whatsapp' => $payload['whatsapp'] ?? null,
            'image' => $payload['image'] ?? null,
            'images' => $payload['images'] ?? [],
            'quantity' => 1,
        ]);

        $upd = $this->pdo->prepare(
            'UPDATE ai_listing_drafts SET status = \'published\', product_id = ?, updated_at = NOW() WHERE id = ?'
        );
        $upd->execute([$productId, $draftId]);

        return ['ok' => true, 'product_id' => $productId];
    }

    /** @param array<string, mixed> $input */
    private function buildDescription(array $input): string
    {
        $lines = [];
        if (!empty($input['brand']) || !empty($input['model'])) {
            $lines[] = 'Товар: ' . trim(($input['brand'] ?? '') . ' ' . ($input['model'] ?? ''));
        }
        if (!empty($input['condition'])) {
            $lines[] = 'Состояние: ' . $input['condition'];
        }
        if (!empty($input['storage'])) {
            $lines[] = 'Память: ' . $input['storage'];
        }
        if (!empty($input['battery'])) {
            $lines[] = 'Батарея: ' . $input['battery'];
        }
        if (!empty($input['color'])) {
            $lines[] = 'Цвет: ' . $input['color'];
        }
        if (!empty($input['extra'])) {
            $lines[] = (string) $input['extra'];
        }
        if (!empty($input['vision']['visible_features']) && is_array($input['vision']['visible_features'])) {
            $lines[] = 'Особенности: ' . implode(', ', $input['vision']['visible_features']);
        }
        if ($lines === []) {
            return 'Описание будет дополнено продавцом.';
        }
        return implode("\n", $lines);
    }
}
