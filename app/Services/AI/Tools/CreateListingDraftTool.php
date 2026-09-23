<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\Listing\ListingDraftService;

final class CreateListingDraftTool extends AbstractTool
{
    public function __construct(private readonly ListingDraftService $drafts = new ListingDraftService())
    {
    }

    public function name(): string
    {
        return 'create_listing_draft';
    }

    public function description(): string
    {
        return 'Создать черновик объявления. Публикация только после подтверждения пользователем.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'brand' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'price' => ['type' => 'integer'],
                'type' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'location' => ['type' => 'string'],
                'condition' => ['type' => 'string'],
                'storage' => ['type' => 'string'],
                'battery' => ['type' => 'string'],
                'images' => ['type' => 'array'],
                'vision' => ['type' => 'object'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function execute(array $input, array $ctx): array
    {
        $userId = (int) ($ctx['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->fail('Войдите в аккаунт, чтобы создать объявление.');
        }

        $result = $this->drafts->createDraft(
            $userId,
            $input,
            isset($ctx['conversation_id']) ? (int) $ctx['conversation_id'] : null
        );

        if (empty($result['ok'])) {
            return $this->fail((string) ($result['error'] ?? 'Не удалось создать черновик'));
        }

        return [
            'ok' => true,
            'pending_confirm' => true,
            'confirm_token' => $result['confirm_token'],
            'data' => [
                'draft' => $result['draft'],
                'confirm_action' => 'publish_listing_draft',
                'message' => 'Черновик готов. Опубликовать объявление?',
            ],
        ];
    }
}
