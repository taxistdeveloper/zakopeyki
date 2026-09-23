<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

/**
 * Пользовательский текст недоверенный. Не даём вытащить system prompt / чужие данные.
 */
final class PromptInjectionGuard
{
    private const PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior|above)\s+instructions/iu',
        '/игнорируй\s+(все\s+)?(предыдущие|прошлые)\s+инструкции/iu',
        '/system\s+prompt/iu',
        '/reveal\s+(your\s+)?(prompt|instructions)/iu',
        '/покажи\s+(системн|промпт|инструкц)/iu',
        '/jailbreak/iu',
        '/DAN\s+mode/iu',
    ];

    public function sanitizeUserText(string $text): string
    {
        $clean = trim($text);
        foreach (self::PATTERNS as $pattern) {
            $clean = preg_replace($pattern, '[filtered]', $clean) ?? $clean;
        }
        // Не даём подменять роли в сыром виде для логов/промпта
        $clean = str_replace(["\0"], '', $clean);
        return $clean;
    }

    public function looksLikeInjection(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }
}
