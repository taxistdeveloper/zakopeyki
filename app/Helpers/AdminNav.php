<?php

namespace App\Helpers;

use App\Core\Auth;

/**
 * Навигация админки: группы разделов, права, счётчики очередей, активный пункт.
 * Используется в partials/sidebar.php (вертикально) и admin/_nav.php (вкладки на мобильных).
 */
final class AdminNav
{
    private static ?array $groups = null;

    public static function isAdminPage(?string $currentNav = null): bool
    {
        if ($currentNav === 'admin') {
            return true;
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return (bool) preg_match('#/admin(/|$)#', $path) && Auth::isStaff();
    }

    /**
     * @return list<array{title: string, items: list<array{path: string, label: string, count: int, tone: string, active: bool}>}>
     */
    public static function groups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $isAdmin = Auth::isAdmin();
        $canTickets = Auth::can('tickets');
        $canAi = Auth::can('ai_chats');
        $c = self::counts($isAdmin, $canTickets, $canAi);

        $flag = static function (callable $fn): bool {
            try {
                return (bool) $fn();
            } catch (\Throwable) {
                return false;
            }
        };

        $raw = [
            [t('admin.nav_group_work'), [
                ['/admin', t('admin.nav_overview'), true, 0, ''],
                ['/admin/tickets', t('admin.tickets'), $canTickets, $c['tickets'], 'danger'],
                ['/admin/ai-chats', t('admin.ai_chats'), $canAi, $c['ai'], 'warn'],
                ['/admin/tasks', t('admin.tasks'), true, $c['tasks'], ''],
            ]],
            [t('admin.nav_group_people'), [
                ['/admin/users', t('admin.users'), $isAdmin, 0, ''],
                ['/admin/business', t('admin.business'), $isAdmin, $c['business'], 'warn'],
                ['/admin/aml', t('admin.aml'), $isAdmin, $c['aml'], 'danger'],
            ]],
            [t('admin.nav_group_money'), [
                ['/admin/paid-services', t('admin.paid_services'), $isAdmin && $flag(fn () => (new \App\Services\PaidServices\PaidServicesAdminService())->isModuleEnabled()), 0, ''],
                ['/admin/finance-journal', t('admin.finance_journal'), $isAdmin && $flag(fn () => (new \App\Services\Finance\FinanceJournalService())->isModuleEnabled()), 0, ''],
                ['/admin/ads', t('nav.ads'), $isAdmin, 0, ''],
                ['/admin/target-push', t('nav.target_push'), $isAdmin, 0, ''],
                ['/admin/mass-push', t('nav.mass_push'), $isAdmin, 0, ''],
            ]],
            [t('admin.nav_group_content'), [
                ['/admin/contests', t('contests.admin.title'), Auth::can('contests'), 0, ''],
                ['/admin/library', t('admin.library'), true, 0, ''],
                ['/admin/gig-categories', t('admin.gig_categories'), $isAdmin, 0, ''],
                ['/admin/stream', t('admin.stream_title'), $isAdmin, 0, ''],
            ]],
            [t('admin.nav_group_system'), [
                ['/admin/ai', 'AI', $canAi, 0, ''],
                ['/admin/logs', t('admin.logs'), $isAdmin, $c['errors'], 'danger'],
            ]],
        ];

        $reqPath = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $best = null;
        $bestLen = -1;
        $groups = [];
        foreach ($raw as $gi => [$title, $items]) {
            $out = [];
            foreach ($items as [$path, $label, $visible, $count, $tone]) {
                if (!$visible) {
                    continue;
                }
                $full = rtrim((string) parse_url(ProductHelper::url($path), PHP_URL_PATH), '/');
                if (($reqPath === $full || str_starts_with($reqPath, $full . '/')) && strlen($full) > $bestLen) {
                    $best = [$gi, count($out)];
                    $bestLen = strlen($full);
                }
                $out[] = ['path' => $path, 'label' => $label, 'count' => (int) $count, 'tone' => $tone, 'active' => false];
            }
            if ($out) {
                $groups[$gi] = ['title' => $title, 'items' => $out];
            }
        }
        if ($best !== null) {
            $groups[$best[0]]['items'][$best[1]]['active'] = true;
        }

        return self::$groups = array_values($groups);
    }

    /** @return array{tickets: int, ai: int, tasks: int, business: int, aml: int, errors: int} */
    private static function counts(bool $isAdmin, bool $canTickets, bool $canAi): array
    {
        $safe = static function (callable $fn): int {
            try {
                return (int) $fn();
            } catch (\Throwable) {
                return 0;
            }
        };

        return [
            'tickets' => $canTickets ? $safe(fn () => (new \App\Models\SupportTicket())->unreadCountForAdmin()) : 0,
            'ai' => $canAi ? $safe(fn () => (new \App\Models\AiSupport())->countEscalated()) : 0,
            'tasks' => $safe(fn () => (new \App\Models\OpsTask())->countOpen()),
            'business' => $isAdmin ? $safe(fn () => (new \App\Models\BusinessUpgradeRequest())->countPending()) : 0,
            'aml' => $isAdmin ? $safe(fn () => (new \App\Models\User())->amlStats()['blocked'] ?? 0) : 0,
            'errors' => $isAdmin ? $safe(fn () => (new \App\Models\ActivityLog())->recentErrorCount(24)) : 0,
        ];
    }
}
