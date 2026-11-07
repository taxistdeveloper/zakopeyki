<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Services\Contest\ContestService;

/**
 * Хелперы отображения модуля конкурсов (экранирование — на стороне вызывающего шаблона,
 * кроме методов, возвращающих готовый HTML: они экранируют сами).
 */
final class ContestView
{
    private const STATUS_CLASS = [
        'draft' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
        'pending_moderation' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'rejected' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'scheduled' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
        'active' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
        'closed' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300',
        'results_published' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
        'completed' => 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300',
        'suspended' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
        'cancelled' => 'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400',
        'disputed' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    ];

    public static function statusBadge(string $status): string
    {
        $cls = self::STATUS_CLASS[$status] ?? self::STATUS_CLASS['draft'];
        return '<span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide ' . $cls . '">'
            . htmlspecialchars(t('contests.status.' . $status)) . '</span>';
    }

    public static function date(?string $dt): string
    {
        $ts = $dt ? strtotime($dt) : false;
        return $ts ? date('d.m.Y H:i', $ts) : '—';
    }

    public static function inputDate(?string $dt): string
    {
        $ts = $dt ? strtotime(str_replace('T', ' ', $dt)) : false;
        return $ts ? date('Y-m-d\TH:i', $ts) : '';
    }

    public static function coverUrl(array $contest): ?string
    {
        $file = (string) ($contest['cover_image'] ?? '');
        if ($file === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $file)) {
            return null;
        }
        return ProductHelper::url(ContestService::COVER_DIR . '/' . $file);
    }

    public static function organizerName(array $contest): string
    {
        $b = trim((string) ($contest['organizer_business_name'] ?? ''));
        return $b !== '' ? $b : (string) ($contest['organizer_name'] ?? '');
    }

    /** Публичное имя победителя: имя + первая буква фамилии (минимизация ПДн). */
    public static function publicWinnerName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return t('contests.anonymous');
        }
        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $first = $parts[0];
        $last = isset($parts[1]) ? ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '';
        return $first . $last;
    }

    /** Оставшееся время до даты в человекочитаемом виде. */
    public static function timeLeft(?string $dt): string
    {
        $ts = $dt ? strtotime($dt) : false;
        if (!$ts) {
            return '';
        }
        $left = $ts - time();
        if ($left <= 0) {
            return '';
        }
        $days = intdiv($left, 86400);
        $hours = intdiv($left % 86400, 3600);
        return $days > 0
            ? t('contests.left_days', ['d' => $days, 'h' => $hours])
            : t('contests.left_hours', ['h' => max(1, $hours)]);
    }
}
