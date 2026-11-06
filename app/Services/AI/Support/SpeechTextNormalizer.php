<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

/**
 * Сервис подготовки и нормализации текста для синтеза речи (TTS).
 * Удаляет Markdown-разметку, HTML-теги, нормализует валюты и числа,
 * сохраняя полный смысл ответа без произнесения технического синтаксиса.
 */
final class SpeechTextNormalizer
{
    public const DEFAULT_MAX_CHARS = 1500;

    /**
     * @param int $maxChars Максимальная длина подготовленного текста
     */
    public function __construct(
        private readonly int $maxChars = self::DEFAULT_MAX_CHARS
    ) {
    }

    public static function clean(string $text, string $language = 'ru', int $maxChars = self::DEFAULT_MAX_CHARS): string
    {
        return (new self($maxChars))->normalize($text, $language);
    }

    public static function truncate(string $text, int $limit): string
    {
        return (new self($limit))->truncateAtSentence($text, $limit);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        if ($name === 'normalize') {
            return (new self())->normalize(...$arguments);
        }
        if ($name === 'truncate') {
            return (new self())->truncateAtSentence(...$arguments);
        }
        throw new \BadMethodCallException("Undefined method {$name}");
    }

    /**
     * Очистка и нормализация текста для речевого синтезатора.
     */
    public function normalize(string $text, string $language = 'ru'): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // 1. Удаление HTML-тегов и скриптов (XSS/SSML защита)
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text) ?? $text;
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text) ?? $text;
        $text = strip_tags($text);

        // 2. Блоки кода и inline backticks
        $text = preg_replace('/```[\s\S]*?```/u', '', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/u', '$1', $text) ?? $text;

        // 3. Markdown ссылки: [Заголовок](https://...) -> Заголовок
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text) ?? $text;

        // 4. Отдельные URL-адреса: убираем длинные протоколы и ссылки
        $text = preg_replace('/https?:\/\/[^\s]+/u', '', $text) ?? $text;

        // 5. Заголовки Markdown (#, ##, ###) -> добавляем точку в конце для естественной паузы
        $text = preg_replace('/^#{1,6}\s*(.+)$/um', '$1.', $text) ?? $text;

        // 6. Выделения: **жирный**, __жирный__, *курсив*, _курсив_, ~~зачёркнутый~~
        $text = preg_replace('/(\*\*|__)(.*?)\1/u', '$2', $text) ?? $text;
        $text = preg_replace('/(\*|_)(.*?)\1/u', '$2', $text) ?? $text;
        $text = preg_replace('/~~(.*?)~~/u', '$1', $text) ?? $text;

        // 7. Списки (маркированные и нумерованные)
        $text = preg_replace('/^\s*[\*\-•]\s+/um', '', $text) ?? $text;
        $text = preg_replace('/^\s*\d+[\.\)]\s+/um', '', $text) ?? $text;

        // 8. Разделители таблиц Markdown (| --- |)
        $text = preg_replace('/\|/u', ' ', $text) ?? $text;
        $text = preg_replace('/[-:]{3,}/u', '', $text) ?? $text;

        // 9. Нормализация валют и сумм для речи
        $text = $this->normalizeCurrencies($text, $language);

        // 10. Удаление эмодзи, чтобы синтезатор не зачитывал технические названия смайликов
        $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}]/u', '', $text) ?? $text;

        // 11. Естественное произношение имени ассистента и частых сокращений
        $text = preg_replace('/\bZAK\b|\bZak\b/u', 'Зак', $text) ?? $text;
        $numberWord = match (strtolower(trim($language))) {
            'kk' => 'нөмір',
            'en' => 'number',
            default => 'номер',
        };
        $text = preg_replace('/(?<!\p{L})№\s*(\d+)/u', $numberWord . ' $1', $text) ?? $text;
        $text = preg_replace('/(\d+)\s*шт\.?/ui', '$1 штук', $text) ?? $text;
        $text = preg_replace('/(\d+)\s*руб\.?/ui', '$1 рублей', $text) ?? $text;

        // 12. Удаление лишних пробелов и дублирующихся знаков препинания
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\r\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([.,!?;:])/u', '$1', $text) ?? $text;
        $text = preg_replace('/([.,!?;:]){2,}/u', '$1', $text) ?? $text;
        $text = trim($text);

        // 13. Ограничение длины по границе предложения
        if (mb_strlen($text, 'UTF-8') > $this->maxChars) {
            $text = $this->truncateAtSentence($text, $this->maxChars);
        }

        return $text;
    }

    /**
     * Преобразование обозначений валют в читаемые слова в зависимости от языка.
     */
    private function normalizeCurrencies(string $text, string $language): string
    {
        $lang = strtolower(trim($language));

        // «тг» → символ ₸, чтобы дальше сработали правила нужного языка (раньше «тг» превращалось
        // в русское «тенге» уже после этого метода, и для казахского получалось «тенге» вместо «теңге»)
        $text = preg_replace('/(\d)\s*тг\b\.?/ui', '$1 ₸', $text) ?? $text;

        // Склейка групп разрядов перед валютой: «1 299 990 ₸» → «1299990 ₸».
        // Только перед валютой — иначе «iPhone 15 256 ГБ» превратилось бы в одно число.
        $text = preg_replace_callback(
            '/(\d{1,3})((?:[\s\x{00A0}]\d{3})+)(?=\s*(?:₸|KZT|теңге|тенге|tenge)(?!\p{L}))/ui',
            static fn (array $m): string => $m[1] . preg_replace('/[\s\x{00A0}]/u', '', $m[2]),
            $text
        ) ?? $text;

        if ($lang === 'kk') {
            // Казахский: 300 000 ₸ -> 300 мың теңге, 1 500 теңге -> 1500 теңге
            $text = preg_replace('/(\d+)[\s\x{00A0}]*000\s*(?:₸|KZT|теңге)/ui', '$1 мың теңге', $text) ?? $text;
            $text = preg_replace('/(\d{1,3})[\s\x{00A0}]+(\d{3})\s*(?:₸|KZT|теңге)/ui', '$1$2 теңге', $text) ?? $text;
            $text = preg_replace('/(\d+)\s*(?:₸|KZT)/ui', '$1 теңге', $text) ?? $text;
            $text = preg_replace('/\$(\d+)/u', '$1 доллар', $text) ?? $text;
        } elseif ($lang === 'en') {
            // Английский: 300,000 tenge / 300 000 ₸ -> 300 thousand tenge
            $text = preg_replace('/(\d+)[,\s\x{00A0}]*000\s*(?:₸|KZT|tenge)/ui', '$1 thousand tenge', $text) ?? $text;
            $text = preg_replace('/(\d{1,3})[,\s\x{00A0}]+(\d{3})\s*(?:₸|KZT|tenge)/ui', '$1$2 tenge', $text) ?? $text;
            $text = preg_replace('/(\d+)\s*(?:₸|KZT)/ui', '$1 tenge', $text) ?? $text;
            $text = preg_replace('/\$(\d+)/u', '$1 dollars', $text) ?? $text;
        } else {
            // Русский: 300 000 ₸ / 300 000 тенге -> 300 тысяч тенге, 1 500 тенге -> 1500 тенге
            $text = preg_replace('/(\d+)[\s\x{00A0}]*000\s*(?:₸|KZT|тенге)/ui', '$1 тысяч тенге', $text) ?? $text;
            $text = preg_replace('/(\d{1,3})[\s\x{00A0}]+(\d{3})\s*(?:₸|KZT|тенге)/ui', '$1$2 тенге', $text) ?? $text;
            $text = preg_replace('/(\d+)\s*(?:₸|KZT)/ui', '$1 тенге', $text) ?? $text;
            $text = preg_replace('/\$(\d+)/u', '$1 долларов', $text) ?? $text;
            $text = preg_replace('/€(\d+)/u', '$1 евро', $text) ?? $text;
        }

        return $text;
    }

    /**
     * Аккуратное усечение текста по границе предложения или слова.
     */
    public function truncateAtSentence(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $sub = mb_substr($text, 0, $limit, 'UTF-8');
        // Ищем последнюю точку, восклицательный или вопросительный знак в пределах лимита
        $lastPunct = max(
            mb_strrpos($sub, '.', 0, 'UTF-8') ?: 0,
            mb_strrpos($sub, '!', 0, 'UTF-8') ?: 0,
            mb_strrpos($sub, '?', 0, 'UTF-8') ?: 0
        );

        if ($lastPunct > 0 && ($lastPunct + 1) <= $limit) {
            return trim(mb_substr($sub, 0, $lastPunct + 1, 'UTF-8'));
        }

        // Если знаков препинания нет, ищем границу слова и оставляем 1 символ для многоточия
        $subEllipsis = mb_substr($text, 0, max(1, $limit - 1), 'UTF-8');
        $lastSpace = mb_strrpos($subEllipsis, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > 0) {
            $subEllipsis = mb_substr($subEllipsis, 0, $lastSpace, 'UTF-8');
        }

        return rtrim($subEllipsis) . '…';
    }
}
