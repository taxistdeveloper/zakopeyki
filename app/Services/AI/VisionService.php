<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Core\ModelRouter;
use App\Services\AI\Providers\OllamaProvider;

/**
 * Анализ фото товара. Неутверждённые факты помечаются как предположения.
 */
final class VisionService
{
    public function __construct(
        private readonly LLMProviderInterface $llm = new OllamaProvider(),
        private readonly ModelRouter $router = new ModelRouter(),
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   analysis?:array<string,mixed>,
     *   raw?:string,
     *   error?:string,
     *   model?:string
     * }
     */
    public function analyzeProductImage(string $imagePathOrBase64, ?string $userHint = null): array
    {
        if (!$this->llm->isAvailable()) {
            return ['ok' => false, 'error' => 'Vision-модель сейчас недоступна. Попробуйте позже или опишите товар текстом.'];
        }

        $hint = $userHint ? "Подсказка пользователя: {$userHint}" : 'Подсказки нет.';
        $prompt = <<<PROMPT
Ты анализируешь фото товара для маркетплейса zakopeyki.kz.
{$hint}

Верни СТРОГО JSON:
{
  "product_type": "",
  "brand": {"value": "", "confidence": 0.0, "certain": false},
  "model": {"value": "", "confidence": 0.0, "certain": false},
  "color": {"value": "", "confidence": 0.0, "certain": false},
  "condition": {"value": "unknown|new|like_new|good|fair|poor", "confidence": 0.0, "certain": false},
  "visible_features": [],
  "possible_defects": [],
  "packaging_text": [],
  "suggested_category": "",
  "suggested_tags": [],
  "uncertainty_notes": []
}

Правила:
- Если не уверен — certain=false и не выдавай догадку за факт.
- Не выдумывай серийные номера и скрытые характеристики.
- uncertainty_notes заполняй честно.
PROMPT;

        try {
            $model = $this->router->modelFor('vision');
            $result = $this->llm->vision($prompt, $imagePathOrBase64, $model);
            $parsed = $this->parseJson($result['content']);
            if ($parsed === null) {
                return [
                    'ok' => true,
                    'analysis' => $this->wrapUncertainRaw($result['content']),
                    'raw' => $result['content'],
                    'model' => $result['model'],
                ];
            }
            $parsed = $this->normalizeAnalysis($parsed);
            return [
                'ok' => true,
                'analysis' => $parsed,
                'raw' => $result['content'],
                'model' => $result['model'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось проанализировать изображение. Попробуйте другое фото.'];
        }
    }

    /** @return array<string, mixed>|null */
    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    private function normalizeAnalysis(array $data): array
    {
        foreach (['brand', 'model', 'color', 'condition'] as $field) {
            if (!isset($data[$field]) || !is_array($data[$field])) {
                $data[$field] = ['value' => '', 'confidence' => 0.0, 'certain' => false];
                continue;
            }
            $certain = !empty($data[$field]['certain']) && (float) ($data[$field]['confidence'] ?? 0) >= 0.75;
            $data[$field]['certain'] = $certain;
            $data[$field]['display'] = $this->displayField($data[$field]);
        }
        $data['visible_features'] = array_values(array_filter((array) ($data['visible_features'] ?? [])));
        $data['possible_defects'] = array_values(array_filter((array) ($data['possible_defects'] ?? [])));
        $data['uncertainty_notes'] = array_values(array_filter((array) ($data['uncertainty_notes'] ?? [])));
        return $data;
    }

    /** @param array{value?:string,certain?:bool} $field */
    private function displayField(array $field): string
    {
        $value = trim((string) ($field['value'] ?? ''));
        if ($value === '' || $value === 'unknown') {
            return 'не определено';
        }
        return !empty($field['certain']) ? $value : ('Предположительно ' . $value);
    }

    private function wrapUncertainRaw(string $raw): array
    {
        return [
            'product_type' => '',
            'brand' => ['value' => '', 'confidence' => 0, 'certain' => false, 'display' => 'не определено'],
            'model' => ['value' => '', 'confidence' => 0, 'certain' => false, 'display' => 'не определено'],
            'color' => ['value' => '', 'confidence' => 0, 'certain' => false, 'display' => 'не определено'],
            'condition' => ['value' => 'unknown', 'confidence' => 0, 'certain' => false, 'display' => 'не определено'],
            'visible_features' => [],
            'possible_defects' => [],
            'packaging_text' => [],
            'suggested_category' => 'Разное',
            'suggested_tags' => [],
            'uncertainty_notes' => ['Модель вернула неструктурированный ответ', mb_substr($raw, 0, 300)],
        ];
    }
}
