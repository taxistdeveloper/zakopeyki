<?php

namespace App\Services;

use App\Helpers\ProductHelper;
use App\Models\Product;

/**
 * Локальный «умный» помощник без внешнего API:
 * FAQ + поиск по каталогу и подсказки разделов.
 */
class CatalogAiAssistant
{
    private const STOP_WORDS = [
        'и', 'в', 'во', 'не', 'что', 'он', 'на', 'я', 'с', 'со', 'как', 'а', 'то', 'все', 'она',
        'так', 'его', 'но', 'да', 'ты', 'к', 'у', 'же', 'вы', 'за', 'бы', 'по', 'только', 'ее',
        'мне', 'было', 'вот', 'от', 'меня', 'еще', 'нет', 'о', 'из', 'ему', 'теперь', 'когда',
        'даже', 'ну', 'вдруг', 'ли', 'если', 'уже', 'или', 'ни', 'быть', 'был', 'него', 'до',
        'вас', 'нибудь', 'опять', 'уж', 'вам', 'ведь', 'там', 'потом', 'себя', 'ничего', 'ей',
        'может', 'они', 'тут', 'где', 'есть', 'надо', 'ней', 'для', 'мы', 'тебя', 'их', 'чем',
        'была', 'сам', 'чтоб', 'без', 'будто', 'человек', 'чего', 'раз', 'тоже', 'себя', 'под',
        'будет', 'ж', 'тогда', 'кто', 'этот', 'того', 'потому', 'этого', 'какой', 'совсем',
        'ним', 'здесь', 'этом', 'один', 'почти', 'мой', 'тем', 'чтобы', 'нее', 'сейчас', 'были',
        'куда', 'зачем', 'сказать', 'всех', 'никогда', 'сегодня', 'можно', 'при', 'наконец',
        'два', 'об', 'другой', 'хоть', 'после', 'над', 'больше', 'тот', 'через', 'эти', 'нас',
        'про', 'всего', 'них', 'какая', 'много', 'разве', 'три', 'эту', 'моя', 'впрочем', 'хорошо',
        'свою', 'этой', 'перед', 'иногда', 'лучше', 'чуть', 'том', 'нельзя', 'такой', 'им', 'более',
        'всегда', 'конечно', 'всю', 'между', 'пожалуйста', 'спасибо', 'здравствуйте', 'привет',
        'хай', 'хеллоу', 'помоги', 'помощь', 'подскажи', 'найди', 'найти', 'ищу', 'хочу', 'нужен',
        'нужна', 'нужно', 'есть', 'покажи', 'покажите', 'расскажи', 'мненя', 'сайте', 'сайт',
        'платформе', 'zakopeyki', 'за копейки', 'товар', 'товары', 'объявление', 'объявления',
    ];

    private const TYPE_HINTS = [
        'free' => ['бесплатно', 'даром', 'отдам', 'отдам даром', 'бесплатный', 'бесплатная', 'халява', 'подарок', 'даром отдам'],
        'exchange' => ['обмен', 'бартер', 'меняю', 'обменю', 'поменять', 'на обмен', 'обменяю', 'в обмен'],
        'auction' => ['аукцион', 'ставка', 'ставки', 'торг', 'лот'],
        'service' => ['услуга', 'услуги', 'мастер', 'ремонт', 'помощь мастера', 'сервис'],
        'course' => ['курс', 'курсы', 'обучение', 'урок', 'уроки', 'тренин'],
        'new' => ['новый', 'новая', 'новое', 'новые', 'маркетплейс'],
        'used' => ['б/у', 'бу', 'б у', 'подержан', 'секонд'],
    ];

    public function reply(string $message): array
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if ($message === '') {
            return $this->pack(
                t('ai.empty'),
                [],
                $this->quickSuggestions()
            );
        }

        $lower = mb_strtolower($message, 'UTF-8');

        if ($faq = $this->matchFaq($lower)) {
            return $faq;
        }

        if ($this->isGreeting($lower)) {
            return $this->pack(
                t('ai.greeting'),
                [],
                $this->quickSuggestions()
            );
        }

        $type = $this->detectType($lower);
        $query = $this->extractSearchQuery($message, $type);

        $products = $this->searchProducts($query, $type, 6);

        if ($products) {
            $typeNote = $type
                ? t('ai.type_note', ['type' => ProductHelper::label($type)])
                : '';
            $qNote = $query !== '' ? t('ai.query_note', ['q' => $query]) : '';
            $text = t('ai.found_simple', [
                'count' => count($products),
                'form' => $this->plural(count($products)),
                'type_note' => $typeNote,
                'q_note' => $qNote,
            ]);
            return $this->pack($text, $products, $this->followUps($type));
        }

        if ($type && $query === '') {
            $label = ProductHelper::label($type);
            return $this->pack(
                t('ai.empty_section', ['label' => $label]),
                [],
                [
                    ['label' => t('ai.suggest_sell'), 'message' => t('ai.msg_sell')],
                    ['label' => t('ai.suggest_free'), 'message' => 'бесплатно'],
                ]
            );
        }

        if ($query !== '') {
            $tips = [];
            if ($type) {
                $tips[] = 'попробуйте без фильтра раздела';
            }
            $tips[] = 'упростите название (например «телефон» вместо модели)';
            $tips[] = 'посмотрите каталог целиком';
            return $this->pack(
                t('ai.not_found'),
                [],
                $this->quickSuggestions()
            );
        }

        return $this->pack(
            t('ai.not_found'),
            [],
            $this->quickSuggestions()
        );
    }

    private function pack(string $reply, array $products, array $suggestions): array
    {
        return [
            'ok' => true,
            'reply' => $reply,
            'products' => $products,
            'suggestions' => $suggestions,
        ];
    }

    private function quickSuggestions(): array
    {
        return [
            ['label' => t('ai.suggest_free'), 'message' => t('ai.msg_free')],
            ['label' => t('ai.suggest_exchange'), 'message' => t('ai.msg_exchange')],
            ['label' => t('ai.suggest_services'), 'message' => t('ai.msg_services')],
            ['label' => t('ai.suggest_sell'), 'message' => t('ai.msg_sell')],
            ['label' => t('ai.suggest_auctions'), 'message' => t('ai.msg_auctions')],
        ];
    }

    private function followUps(?string $type): array
    {
        $items = [
            ['label' => t('ai.suggest_cheap'), 'message' => t('ai.msg_cheap')],
            ['label' => t('ai.suggest_free'), 'message' => t('ai.msg_free')],
        ];
        if ($type !== 'exchange') {
            $items[] = ['label' => t('ai.suggest_exchange'), 'message' => t('ai.msg_exchange')];
        }
        if ($type !== 'service') {
            $items[] = ['label' => t('ai.suggest_services'), 'message' => t('ai.msg_services')];
        }
        if ($type !== 'free') {
            $items[] = [
                'label' => t('ai.suggest_more'),
                'message' => $type ? ProductHelper::label($type) : t('ai.msg_services'),
            ];
        }
        return array_slice($items, 0, 4);
    }

    private function isGreeting(string $lower): bool
    {
        return (bool) preg_match('/^(привет|здравствуй|здравствуйте|добрый\s+(день|вечер|утро)|хай|hello|hi|сәлем|салем)\b/u', $lower)
            || in_array($lower, ['помощь', 'помоги', 'что ты умеешь', 'кто ты', 'көмек'], true);
    }

    private function matchFaq(string $lower): ?array
    {
        if (preg_match('/(как|где|қалай).*(размест|прода|добав|создат|вылож|объявлен|орналас|сату)/u', $lower)
            || preg_match('/(продать|разместить|добавить|сату).*(товар|объявлен|лот|хабар)/u', $lower)) {
            return $this->pack(
                t('ai.faq_sell'),
                [],
                [
                    ['label' => t('ai.suggest_login'), 'message' => t('ai.msg_login')],
                    ['label' => t('ai.suggest_search'), 'message' => t('ai.msg_search_phone')],
                ]
            );
        }

        if (preg_match('/(войти|вход|регистрац|аккаунт|логин|кіру|тіркел)/u', $lower)) {
            return $this->pack(
                t('ai.faq_login'),
                [],
                [
                    ['label' => t('ai.suggest_sell'), 'message' => t('ai.msg_sell')],
                    ['label' => t('ai.suggest_free'), 'message' => t('ai.msg_free')],
                ]
            );
        }

        if (preg_match('/избранн|таңдаул/u', $lower)) {
            return $this->pack(t('ai.faq_fav'), [], $this->quickSuggestions());
        }

        if (preg_match('/(аукцион|ставк|аукцион)/u', $lower) && !preg_match('/найд|ищу|покаж|есть ли|тап|ізде/u', $lower)) {
            $products = $this->searchProducts('', 'auction', 6);
            $text = t('ai.faq_auction') . ($products ? t('ai.faq_auction_lots') : t('ai.faq_auction_empty'));
            return $this->pack($text, $products, [
                ['label' => t('ai.suggest_free'), 'message' => t('ai.msg_free')],
                ['label' => t('ai.suggest_bid'), 'message' => t('ai.msg_bid')],
            ]);
        }

        if (preg_match('/как.*(ставк|торг)|ставка қалай|ставк/u', $lower) && preg_match('/как|қалай|сделать|жаса/u', $lower)) {
            return $this->pack(
                t('ai.faq_bid'),
                [],
                [['label' => t('ai.suggest_show_auctions'), 'message' => t('ai.msg_auctions')]]
            );
        }

        if (preg_match('/(раздел|категор|что.*есть|чем.*польз|как.*искат|бөлім)/u', $lower)) {
            return $this->pack(t('ai.faq_sections'), [], $this->quickSuggestions());
        }

        if (preg_match('/(доставк|самовывоз|оплат|безопас|жеткізу)/u', $lower)) {
            return $this->pack(t('ai.faq_deal'), [], $this->quickSuggestions());
        }

        return null;
    }

    private function detectType(string $lower): ?string
    {
        foreach (self::TYPE_HINTS as $type => $words) {
            foreach ($words as $word) {
                if (mb_strpos($lower, $word) !== false) {
                    return $type;
                }
            }
        }
        return null;
    }

    private function extractSearchQuery(string $message, ?string $type): string
    {
        $clean = mb_strtolower($message, 'UTF-8');
        $clean = preg_replace('/[^\p{L}\p{N}\s\/\-]+/u', ' ', $clean) ?? '';
        $parts = preg_split('/\s+/u', trim($clean)) ?: [];

        $typeWords = [];
        if ($type) {
            foreach (self::TYPE_HINTS[$type] ?? [] as $w) {
                foreach (preg_split('/\s+/u', $w) ?: [] as $piece) {
                    $typeWords[$piece] = true;
                }
            }
        }

        $kept = [];
        foreach ($parts as $part) {
            if ($part === '' || mb_strlen($part, 'UTF-8') < 2) {
                continue;
            }
            if (isset($typeWords[$part])) {
                continue;
            }
            if (in_array($part, self::STOP_WORDS, true)) {
                continue;
            }
            $kept[] = $part;
        }

        // «дешёвые / недорого» — без конкретного товара
        if ($kept === ['дешевые'] || $kept === ['дешёвые'] || $kept === ['недорого'] || $kept === ['недорогие']) {
            return '';
        }

        return trim(implode(' ', array_slice($kept, 0, 6)));
    }

    private function searchProducts(string $query, ?string $type, int $limit): array
    {
        $model = new Product();

        // Дешёвые / недорого без запроса — сортировка по цене
        $cheap = $query === '' && $type === null;

        if ($query !== '') {
            $rows = $model->allActive($type, $query);
            if (!$rows && str_contains($query, ' ')) {
                // Fallback: ищем по каждому слову и собираем уникальные
                $seen = [];
                $rows = [];
                foreach (explode(' ', $query) as $word) {
                    if (mb_strlen($word, 'UTF-8') < 3) {
                        continue;
                    }
                    foreach ($model->allActive($type, $word) as $row) {
                        $id = (int) $row['id'];
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            $rows[] = $row;
                        }
                    }
                }
            }
        } else {
            $rows = $model->allActive($type, null);
        }

        if ($cheap || preg_match('/деш[её]в|недорог/u', mb_strtolower($query, 'UTF-8'))) {
            usort($rows, static function ($a, $b) {
                return (int) $a['price'] <=> (int) $b['price'];
            });
        }

        $rows = array_slice($rows, 0, $limit);
        return array_map([$this, 'serializeProduct'], $rows);
    }

    private function serializeProduct(array $item): array
    {
        $out = [
            'id' => (int) $item['id'],
            'title' => (string) $item['title'],
            'price' => ProductHelper::formatPrice($item),
            'type' => (string) $item['type'],
            'type_label' => ProductHelper::label((string) $item['type']),
            'location' => (string) ($item['location'] ?? ''),
            'url' => ProductHelper::url('/product/' . (int) $item['id']),
            'image' => ProductHelper::imageUrl($item),
        ];
        if (($item['type'] ?? '') === 'exchange' && !empty($item['exchange_for'])) {
            $out['exchange_for'] = (string) $item['exchange_for'];
        }
        return $out;
    }

    private function plural(int $n): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return 'ов';
        }
        if ($n1 > 1 && $n1 < 5) {
            return 'а';
        }
        if ($n1 === 1) {
            return '';
        }
        return 'ов';
    }
}
