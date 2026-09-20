<?php

namespace App\Helpers;

class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'div',
        'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_ATTR = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $wrapped = '<div id="__sanitize_root">' . $html . '</div>';
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $dom->getElementById('__sanitize_root');
        if (!$root) {
            return '';
        }

        self::scrub($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private static function scrub(\DOMNode $node): void
    {
        $toRemove = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    $toRemove[] = $child;
                    continue;
                }
                self::scrubAttributes($child);
                self::scrub($child);
            } elseif ($child instanceof \DOMComment) {
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $dead) {
            $node->removeChild($dead);
        }
    }

    private static function scrubAttributes(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);
        $allowed = self::ALLOWED_ATTR[$tag] ?? [];
        $remove = [];
        if ($el->hasAttributes()) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                    $remove[] = $attr->name;
                    continue;
                }
                $value = trim($attr->value);
                if ($name === 'href' || $name === 'src') {
                    if (!self::isSafeUrl($value, $name === 'src')) {
                        $remove[] = $attr->name;
                    }
                }
                if ($name === 'target' && $value !== '_blank') {
                    $remove[] = $attr->name;
                }
            }
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function isSafeUrl(string $url, bool $allowDataImage): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        if ($allowDataImage && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $url)) {
            return true;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
