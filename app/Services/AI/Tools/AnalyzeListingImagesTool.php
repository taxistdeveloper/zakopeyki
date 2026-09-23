<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AI\VisionService;

final class AnalyzeListingImagesTool extends AbstractTool
{
    public function __construct(private readonly VisionService $vision = new VisionService())
    {
    }

    public function name(): string
    {
        return 'analyze_listing_images';
    }

    public function description(): string
    {
        return 'Анализ фотографии товара (тип, бренд, модель, состояние) без утверждения неуверенных фактов.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'image_path' => ['type' => 'string'],
                'image_base64' => ['type' => 'string'],
                'hint' => ['type' => 'string'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'user';
    }

    public function execute(array $input, array $ctx): array
    {
        $image = (string) ($input['image_path'] ?? $input['image_base64'] ?? '');
        if ($image === '') {
            return $this->fail('Нужно изображение для анализа.');
        }
        if (isset($input['image_path']) && !is_file((string) $input['image_path'])) {
            return $this->fail('Файл изображения не найден.');
        }

        $result = $this->vision->analyzeProductImage($image, isset($input['hint']) ? (string) $input['hint'] : null);
        if (empty($result['ok'])) {
            return $this->fail((string) ($result['error'] ?? 'Ошибка анализа'));
        }

        return $this->ok([
            'analysis' => $result['analysis'],
            'model' => $result['model'] ?? null,
            'source' => 'vision.ollama',
            'data_timestamp' => date('c'),
        ]);
    }
}
