<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\DTO\IntentResult;
use App\Services\AI\Enum\Intent;
use App\Services\AI\Providers\OllamaProvider;

/**
 * NLU: эвристики + LLM JSON для параметров поиска/действий.
 */
final class IntentDetector
{
    public function __construct(
        private readonly LLMProviderInterface $llm = new OllamaProvider(),
        private readonly LanguageDetector $lang = new LanguageDetector(),
    ) {
    }

    public function detect(string $message, ?string $languageHint = null, array $pageContext = []): IntentResult
    {
        $language = $this->lang->detect($message, $languageHint);
        $lower = mb_strtolower(trim($message), 'UTF-8');

        $fast = $this->fastPath($lower, $pageContext);
        if ($fast !== null) {
            $params = $this->extractSearchParamsHeuristic($message, $lower);
            if ($fast === Intent::SearchProduct && $params !== []) {
                return new IntentResult($fast, 0.92, 'heuristic', $params, $language);
            }
            if (in_array($fast, [Intent::OrderStatus, Intent::DeliveryStatus], true)) {
                $params = $this->extractOrderParams($message);
            }
            return new IntentResult($fast, 0.95, 'heuristic', $params, $language);
        }

        if ($this->llm->isAvailable()) {
            try {
                return $this->detectViaLlm($message, $language, $pageContext);
            } catch (\Throwable) {
                // fallback below
            }
        }

        if ($this->looksLikeSearch($lower)) {
            return new IntentResult(
                Intent::SearchProduct,
                0.75,
                'fallback',
                $this->extractSearchParamsHeuristic($message, $lower),
                $language
            );
        }

        return new IntentResult(Intent::Support, 0.6, 'fallback', [], $language);
    }

    private function fastPath(string $lower, array $pageContext): ?Intent
    {
        $escalate = ['оператор', 'живой', 'специалист', 'мошенник', 'обман', 'полиция', 'жалоба'];
        foreach ($escalate as $w) {
            if (mb_strpos($lower, $w) !== false) {
                return Intent::HumanEscalate;
            }
        }

        $greetings = ['привет', 'здравствуйте', 'здравствуй', 'добрый день', 'сәлем', 'сәлеметсіз бе', 'hello', 'hi'];
        if (in_array($lower, $greetings, true)) {
            return Intent::Greeting;
        }

        if (preg_match('/(где|статус).*(посылк|доставк|заказ)|посылка|трек|tracking|жеткізу/u', $lower)) {
            return Intent::DeliveryStatus;
        }

        if (preg_match('/\b(заказ|order)\s*#?\s*\d+/u', $lower) || preg_match('/#\d{2,}/u', $lower)) {
            return Intent::OrderStatus;
        }

        if (preg_match('/(возврат|верн|return|қайтар)/u', $lower)) {
            return Intent::ReturnRequest;
        }

        if (preg_match('/(спор|dispute|арбитраж)/u', $lower)) {
            return Intent::DisputeRequest;
        }

        // Seller help BEFORE generic "продать"
        if (preg_match('/(как\s+быстрее\s+продать|что\s+(мне\s+)?сделать.*продать|рекомендац.*продаж|sales recommend)/u', $lower)) {
            return Intent::SalesRecommendation;
        }

        if (preg_match('/(статистик|мои продажи|analytics)/u', $lower)
            || preg_match('/\bпродаж\b/u', $lower)
        ) {
            return Intent::SellerAnalytics;
        }

        if (preg_match('/(спрос|demand|конкуренц|рынок\s+по)/u', $lower)) {
            return Intent::DemandAnalysis;
        }

        if (preg_match('/(хочу\s+продать|продам|вылож|создать объявлен|сату|орналас|продать\s+(этот|айфон|iphone|телефон|товар))/u', $lower)) {
            return Intent::CreateListing;
        }

        if (preg_match('/(какую цену|сколько стоит|оцен(и|ка)|рекоменд.*цен|price recommend)/u', $lower)) {
            return Intent::PriceRecommendation;
        }

        if (preg_match('/(избранн|favorites|таңдаул)/u', $lower)) {
            return Intent::Favorites;
        }

        if (preg_match('/(сравни|compare)/u', $lower)) {
            return Intent::ProductComparison;
        }

        if (!empty($pageContext['product_id']) && preg_match('/(цен|сниз|дорог|рекоменд)/u', $lower)) {
            return Intent::PriceRecommendation;
        }

        if ($this->looksLikeSearch($lower)) {
            return Intent::SearchProduct;
        }

        return null;
    }

    private function looksLikeSearch(string $lower): bool
    {
        $hints = ['найди', 'ищу', 'покажи', 'есть ли', 'find', 'search', 'тап', 'ізде', 'iphone', 'айфон', 'ноутбук', 'телефон'];
        foreach ($hints as $h) {
            if (mb_strpos($lower, $h) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function extractSearchParamsHeuristic(string $message, ?string $lower = null): array
    {
        $lower ??= mb_strtolower($message, 'UTF-8');
        $params = [];

        // цена: до 300000 / до 300 тысяч / до 300к
        if (preg_match('/до\s+(\d[\d\s]*)\s*(тысяч|тыс\.?|к|k)?/ui', $lower, $m)) {
            $num = (int) preg_replace('/\s+/', '', $m[1]);
            $mul = $m[2] ?? '';
            if ($mul !== '' && preg_match('/тысяч|тыс|к|k/ui', $mul)) {
                $num *= 1000;
            } elseif ($num > 0 && $num < 1000 && $mul === '') {
                // «до 300» в контексте тысяч часто = 300000 для телефонов — оставляем как есть если > 1000
            }
            // «300 тысяч» уже учтено; «до трехсот тысяч» — ниже словами
            if ($num > 0) {
                $params['max_price'] = $num;
                $params['currency'] = 'KZT';
            }
        }

        if (preg_match('/до\s+(трёхсот|трехсот|триста)\s+тысяч/u', $lower)) {
            $params['max_price'] = 300000;
            $params['currency'] = 'KZT';
        }
        if (preg_match('/до\s+(двухсот|двести)\s+тысяч/u', $lower)) {
            $params['max_price'] = 200000;
            $params['currency'] = 'KZT';
        }
        if (preg_match('/до\s+(ста|сто)\s+тысяч/u', $lower)) {
            $params['max_price'] = 100000;
            $params['currency'] = 'KZT';
        }

        if (preg_match('/от\s+(\d[\d\s]*)/u', $lower, $m)) {
            $params['min_price'] = (int) preg_replace('/\s+/', '', $m[1]);
        }

        // города
        $cities = [
            'алматы' => 'Алматы', 'almaty' => 'Алматы',
            'астана' => 'Астана', 'astana' => 'Астана', 'нур-султан' => 'Астана',
            'караганда' => 'Караганда', 'қарағанды' => 'Караганда',
            'шымкент' => 'Шымкент', 'актобе' => 'Актобе',
        ];
        foreach ($cities as $needle => $label) {
            if (mb_strpos($lower, $needle) !== false) {
                $params['location'] = $label;
                break;
            }
        }

        if (preg_match('/(с доставк|доставк|delivery)/u', $lower)) {
            $params['delivery'] = true;
        }

        if (preg_match('/\b(новый|новая|новое|new)\b/u', $lower)) {
            $params['condition'] = 'new';
            $params['type'] = 'new';
        } elseif (preg_match('/\b(б\/у|бу|used|подержан)\b/u', $lower)) {
            $params['condition'] = 'used';
            $params['type'] = 'used';
        }

        // бренд/модель
        if (preg_match('/(iphone|айфон)\s*(15|15\s*pro|14|13|12|11|se)?/ui', $message, $m)) {
            $params['brand'] = 'Apple';
            $model = 'iPhone';
            if (!empty($m[2])) {
                $model .= ' ' . preg_replace('/\s+/', ' ', trim($m[2]));
            }
            $params['model'] = $model;
            $params['query'] = trim($model);
            $params['category'] = 'smartphone';
        } elseif (preg_match('/(samsung|самсунг)\s*([a-z0-9\s\+]+)?/ui', $message, $m)) {
            $params['brand'] = 'Samsung';
            $params['query'] = trim('Samsung ' . ($m[2] ?? ''));
        } elseif (preg_match('/(ноутбук|laptop|макбук|macbook)/ui', $message, $m)) {
            $params['query'] = trim($m[1]);
            $params['category'] = 'laptop';
        }

        if (empty($params['query'])) {
            $params['query'] = $this->stripSearchNoise($message);
        }

        return array_filter($params, static fn ($v) => $v !== null && $v !== '');
    }

    private function stripSearchNoise(string $message): string
    {
        $clean = mb_strtolower($message, 'UTF-8');
        $noise = [
            'найди мне', 'найди', 'покажи', 'ищу', 'есть ли', 'пожалуйста', 'в алматы', 'в астане',
            'до трехсот тысяч', 'до трёхсот тысяч', 'тенге', 'желательно', 'с доставкой',
            'find me', 'find', 'show me', 'please',
        ];
        foreach ($noise as $n) {
            $clean = str_replace($n, ' ', $clean);
        }
        $clean = preg_replace('/до\s+\d[\d\s]*(тысяч|тыс\.?|к|k)?/ui', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    /** @return array<string, mixed> */
    private function extractOrderParams(string $message): array
    {
        $params = [];
        if (preg_match('/#?\s*(\d{2,})/u', $message, $m)) {
            $params['order_id'] = (int) $m[1];
        }
        return $params;
    }

    private function detectViaLlm(string $message, string $language, array $pageContext): IntentResult
    {
        $intentList = implode(', ', array_map(static fn (Intent $i) => $i->value, Intent::cases()));
        $ctx = $pageContext !== [] ? json_encode($pageContext, JSON_UNESCAPED_UNICODE) : '{}';

        $system = <<<PROMPT
Ты классификатор намерений маркетплейса zakopeyki.kz.
Верни ТОЛЬКО JSON:
{
  "intent": "<один из: {$intentList}>",
  "confidence": 0.0,
  "language": "ru|kk|en",
  "parameters": {
    "query": "",
    "brand": "",
    "model": "",
    "max_price": null,
    "min_price": null,
    "currency": "KZT",
    "location": "",
    "type": "",
    "condition": "",
    "order_id": null,
    "product_id": null,
    "delivery": false
  }
}
Контекст страницы: {$ctx}
Не выдумывай параметры, которых нет в сообщении.
PROMPT;

        $result = $this->llm->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $message],
            ],
            0.0,
            true
        );

        $parsed = json_decode($result['content'], true);
        if (!is_array($parsed) || empty($parsed['intent'])) {
            throw new \RuntimeException('Bad intent JSON');
        }

        $intent = Intent::tryFromLoose((string) $parsed['intent']);
        $confidence = max(0.0, min(1.0, (float) ($parsed['confidence'] ?? 0.7)));
        $params = is_array($parsed['parameters'] ?? null) ? $parsed['parameters'] : [];
        $lang = in_array(($parsed['language'] ?? ''), ['ru', 'kk', 'en'], true)
            ? (string) $parsed['language']
            : $language;

        // Дополняем эвристикой цены/города если LLM пропустил
        $heuristic = $this->extractSearchParamsHeuristic($message);
        $params = array_merge($heuristic, array_filter($params, static fn ($v) => $v !== null && $v !== ''));

        return new IntentResult($intent, $confidence, 'llm', $params, $lang);
    }
}
