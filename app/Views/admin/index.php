<?php
use App\Helpers\ProductHelper;

$canTickets = !empty($canTickets);
$canAi = !empty($canAi);
$canProducts = !empty($canProducts);
$canDisputes = !empty($canDisputes);
$isAdmin = !empty($isAdmin);
$counts = $counts ?? [];
$items = $items ?? [];
$disputes = $disputes ?? [];
$recentErrors = (int) ($recentErrors ?? 0);
$stubMode = !empty($stubMode);
$adsFlags = is_array($adsFlags ?? null) ? $adsFlags : [];
$e = static fn ($s): string => htmlspecialchars((string) $s);
$u = static fn (string $p): string => ProductHelper::url($p);
$cap = static fn (int $n): string => $n > 99 ? '99+' : (string) $n;

/*
 * Модули: [название, подсказка, включён, action, доп.поля, confirm вкл, confirm выкл, recover action, recover label, ссылка, подпись ссылки]
 */
$modules = [];
if ($isAdmin) {
    $simple = static function (string $k, bool $on, string $action, ?string $recover = null, ?string $open = null, ?string $openLabel = null, array $extra = []) {
        return [
            'name' => t("admin.{$k}_status"),
            'hint' => t("admin.{$k}_hint"),
            'on' => $on,
            'action' => $action,
            'extra' => $extra,
            'confirm_on' => t("admin.{$k}_enable_confirm"),
            'confirm_off' => t("admin.{$k}_disable_confirm"),
            'label_on' => t("admin.{$k}_enable_action"),
            'label_off' => t("admin.{$k}_disable_action"),
            'recover' => $recover,
            'recover_label' => $recover ? t("admin.{$k}_recover") : null,
            'open' => $open,
            'open_label' => $openLabel,
        ];
    };
    $paidOn = !empty($paidServicesAdminEnabled);
    $finOn = !empty($financeJournalEnabled);
    $threeOn = !empty($threeLiftsEnabled);

    $p = $simple('paid_services_module', $paidOn, '/admin/paid-services/module', null, $paidOn ? '/admin/paid-services' : null, t('admin.paid_services_module_open'));
    $p['name'] = t('admin.paid_services');
    $modules[] = $p;
    $f = $simple('finance_journal_module', $finOn, '/admin/finance-journal/module', null, $finOn ? '/admin/finance-journal' : null, t('admin.finance_journal_module_open'));
    $f['name'] = t('admin.finance_journal');
    $modules[] = $f;
    $modules[] = $simple('service_listing', !empty($serviceListingPublishEnabled), '/admin/service-listing-publish');
    $modules[] = $simple('boost', !empty($boostEnabled), '/admin/boost', !empty($boostEnabled) ? '/admin/boost/recover' : null);
    $modules[] = $simple('three_lifts', $threeOn, '/admin/three-lifts', null, null, null, ['expected' => $threeOn ? '1' : '0']);
    $modules[] = $simple('highlight', !empty($highlightEnabled), '/admin/highlight', !empty($highlightEnabled) ? '/admin/highlight/recover' : null);
    $modules[] = $simple('top_fixed', !empty($topFixedEnabled), '/admin/top-fixed', !empty($topFixedEnabled) ? '/admin/top-fixed/recover' : null);
    $modules[] = $simple('top_fixed_xl', !empty($topFixedXlEnabled), '/admin/top-fixed-xl', !empty($topFixedXlEnabled) ? '/admin/top-fixed-xl/recover' : null);
    $modules[] = $simple('vip', !empty($vipEnabled), '/admin/vip', !empty($vipEnabled) ? '/admin/vip/recover' : null);
    foreach (['hero', 'category', 'sidebar', 'exclusive'] as $fmt) {
        $on = !empty($adsFlags[$fmt]);
        $name = t('ads.format_' . $fmt);
        $modules[] = [
            'name' => t('admin.ads_switch') . ' · ' . $name,
            'hint' => t('admin.ads_switch_hint', ['rate' => t('ads.rate_' . $fmt)]),
            'on' => $on,
            'action' => '/admin/ads/' . $fmt,
            'extra' => [],
            'confirm_on' => t('admin.ads_enable_confirm', ['name' => $name]),
            'confirm_off' => t('admin.ads_disable_confirm', ['name' => $name]),
            'label_on' => t('admin.ads_enable_action'),
            'label_off' => t('admin.ads_disable_action'),
            'recover' => $on ? '/admin/ads/' . $fmt . '/recover' : null,
            'recover_label' => t('admin.ads_recover'),
            'open' => '/admin/ads',
            'open_label' => t('admin.ads_moderation'),
        ];
    }
    $modules[] = $simple('target_push', !empty($targetPushEnabled), '/admin/target-push', null, '/admin/target-push', t('admin.target_push_moderation'));
    $modules[] = $simple('mass_push', !empty($massPushEnabled), '/admin/mass-push', null, '/admin/mass-push', t('admin.mass_push_moderation'));
}

/* Очереди, требующие внимания */
$attention = [];
if ($canTickets) {
    $attention[] = ['/admin/tickets', t('admin.tickets'), t('admin.tickets_hint'), (int) ($ticketUnread ?? 0), (int) ($openTickets ?? 0), 'danger'];
}
if ($canAi) {
    $attention[] = ['/admin/ai-chats', t('admin.dash_escalated'), t('admin.ai_chats_hint'), (int) ($aiEscalated ?? 0), null, 'warn'];
}
$attention[] = ['/admin/tasks', t('admin.dash_open_tasks'), t('admin.tasks_hint'), (int) ($openTasks ?? 0), null, 'warn'];
if ($isAdmin) {
    $attention[] = ['/admin/aml', t('admin.dash_aml_blocked'), t('admin.aml_hint'), (int) ($amlBlockedCount ?? 0), null, 'danger'];
    $attention[] = ['/admin/logs', t('admin.dash_errors_24h'), t('admin.logs_hint'), $recentErrors, null, 'danger'];
}
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.heading')) ?></h1>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($isAdmin):
        $userStats = $userStats ?? [];
        $visitStats = $visitStats ?? [];
        $recentVisitors = $recentVisitors ?? [];
    ?>
    <div class="adm-stats" style="--cols:4">
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_visitors_today')) ?></div>
            <div class="adm-stat-value"><?= (int) ($visitStats['visitors_today'] ?? 0) ?></div>
            <div class="adm-stat-meta"><?= (int) ($visitStats['hits_today'] ?? 0) ?> <?= $e(t('admin.stats_hits_label')) ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_visitors_week')) ?></div>
            <div class="adm-stat-value"><?= (int) ($visitStats['visitors_week'] ?? 0) ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_visitors_total')) ?></div>
            <div class="adm-stat-value"><?= (int) ($visitStats['visitors_total'] ?? 0) ?></div>
        </div>
        <a class="adm-stat" href="<?= $u('/admin/users') ?>">
            <div class="adm-stat-label"><?= $e(t('admin.stats_users_total')) ?></div>
            <div class="adm-stat-value"><?= (int) ($userStats['total'] ?? $userCount ?? 0) ?></div>
            <div class="adm-stat-meta">+<?= (int) ($userStats['today'] ?? 0) ?> <?= $e(t('admin.stats_today_short')) ?> · +<?= (int) ($userStats['week'] ?? 0) ?> <?= $e(t('admin.stats_week_short')) ?></div>
        </a>
    </div>
    <?php endif; ?>

    <div class="adm-grid-2">
        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title"><?= $e(t('admin.dash_attention')) ?></h2>
            </div>
            <div class="adm-list">
                <?php foreach ($attention as [$href, $label, $hint, $count, $total, $tone]): ?>
                    <a class="adm-row" href="<?= $u($href) ?>">
                        <div class="adm-row-main">
                            <div class="adm-row-title"><?= $e($label) ?></div>
                            <div class="adm-row-sub truncate"><?= $e($hint) ?></div>
                        </div>
                        <div class="adm-row-side">
                            <?php if ($total !== null): ?><span class="subtle"><?= $e(t('admin.dash_open_total', ['n' => $total])) ?></span><?php endif; ?>
                            <span class="adm-count<?= $count > 0 ? ' adm-count--' . $tone : '' ?>"><?= $cap($count) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php if ($canDisputes): ?>
                    <a class="adm-row" href="#disputes">
                        <div class="adm-row-main">
                            <div class="adm-row-title"><?= $e(t('admin.dash_disputes')) ?></div>
                            <div class="adm-row-sub truncate"><?= $e(t('admin.disputes_hint')) ?></div>
                        </div>
                        <div class="adm-row-side"><span class="adm-count<?= count($disputes) > 0 ? ' adm-count--warn' : '' ?>"><?= $cap(count($disputes)) ?></span></div>
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($isAdmin): ?>
        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2 class="adm-card-title"><?= $e(t('admin.stats_recent_visitors')) ?></h2>
                    <p class="adm-card-sub"><?= $e(t('admin.stats_recent_visitors_hint')) ?></p>
                </div>
                <?php if (!empty($recentVisitors)): ?><span class="adm-count"><?= count($recentVisitors) ?></span><?php endif; ?>
            </div>
            <?php if (empty($recentVisitors)): ?>
                <div class="adm-empty"><?= $e(t('admin.stats_visitors_empty')) ?></div>
            <?php else: ?>
                <div class="adm-list" style="max-height: 340px; overflow-y: auto;">
                    <?php foreach ($recentVisitors as $v):
                        $uid = (int) ($v['user_id'] ?? 0);
                        $name = trim((string) ($v['user_name'] ?? ''));
                        $label = $name !== '' ? $name : t('admin.stats_guest');
                        $ip = (string) ($v['ip'] ?? '');
                    ?>
                        <div class="adm-row">
                            <span class="adm-avatar"><?= $e(mb_strtoupper(mb_substr($label, 0, 1))) ?></span>
                            <div class="adm-row-main">
                                <?php if ($uid > 0): ?>
                                    <a class="adm-row-title adm-link" style="color:inherit" href="<?= $u('/admin/users/' . $uid) ?>"><?= $e($label) ?></a>
                                <?php else: ?>
                                    <div class="adm-row-title subtle"><?= $e($label) ?></div>
                                <?php endif; ?>
                                <div class="adm-row-sub truncate mono"><?= $e($v['path'] ?? '/') ?><?= $ip !== '' ? ' · ' . $e($ip) : '' ?> · <?= (int) ($v['hits'] ?? 1) ?> <?= $e(t('admin.stats_hits_label')) ?></div>
                            </div>
                            <div class="adm-row-side nowrap"><?= $e(substr((string) ($v['last_seen_at'] ?? ''), 0, 16)) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <section class="adm-card">
        <div class="adm-card-head">
            <div>
                <h2 class="adm-card-title"><?= $e(t('admin.dash_modules')) ?></h2>
                <p class="adm-card-sub"><?= $e(t('admin.dash_modules_hint')) ?></p>
            </div>
        </div>
        <div class="adm-table-wrap">
            <table class="adm-table">
                <thead>
                    <tr>
                        <th><?= $e(t('admin.dash_module')) ?></th>
                        <th><?= $e(t('admin.dash_state')) ?></th>
                        <th class="num"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="min-width:260px">
                            <div class="strong"><?= $e(t('admin.site_status')) ?></div>
                            <div class="muted" style="font-size:12px;margin-top:2px"><?= $e(t('admin.site_status_hint')) ?></div>
                        </td>
                        <td class="nowrap">
                            <span class="adm-badge adm-badge--<?= $stubMode ? 'warn' : 'ok' ?>"><span class="adm-dot"></span><?= $e($stubMode ? t('admin.dash_site_closed') : t('admin.dash_site_open')) ?></span>
                        </td>
                        <td>
                            <div class="actions">
                                <form method="post" action="<?= $u('/admin/site-status') ?>" class="adm-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="open" value="<?= $stubMode ? '1' : '0' ?>">
                                    <button type="submit" class="adm-btn adm-btn--sm<?= $stubMode ? '' : ' adm-btn--danger' ?>"
                                        onclick="return confirm(<?= $e(json_encode($stubMode ? t('admin.site_open_confirm') : t('admin.site_close_confirm'))) ?>)">
                                        <?= $e($stubMode ? t('admin.site_open_action') : t('admin.site_close_action')) ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php foreach ($modules as $m): ?>
                    <tr>
                        <td style="min-width:260px">
                            <div class="strong"><?= $e($m['name']) ?></div>
                            <div class="muted adm-clamp-2" style="font-size:12px;margin-top:2px;max-width:72ch" title="<?= $e($m['hint']) ?>"><?= $e($m['hint']) ?></div>
                        </td>
                        <td class="nowrap">
                            <span class="adm-badge adm-badge--<?= $m['on'] ? 'ok' : 'neutral' ?>"><span class="adm-dot"></span><?= $e($m['on'] ? t('admin.dash_on') : t('admin.dash_off')) ?></span>
                        </td>
                        <td>
                            <div class="actions">
                                <?php if ($m['open']): ?>
                                    <a class="adm-link" href="<?= $u($m['open']) ?>"><?= $e($m['open_label']) ?></a>
                                <?php endif; ?>
                                <?php if ($m['recover']): ?>
                                    <form method="post" action="<?= $u($m['recover']) ?>" class="adm-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="adm-btn adm-btn--sm"><?= $e($m['recover_label']) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= $u($m['action']) ?>" class="adm-inline">
                                    <?= csrf_field() ?>
                                    <?php foreach ($m['extra'] as $k => $v): ?>
                                        <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
                                    <?php endforeach; ?>
                                    <input type="hidden" name="enabled" value="<?= $m['on'] ? '0' : '1' ?>">
                                    <button type="submit" class="adm-switch" aria-pressed="<?= $m['on'] ? 'true' : 'false' ?>"
                                        aria-label="<?= $e($m['on'] ? $m['label_off'] : $m['label_on']) ?>"
                                        title="<?= $e($m['on'] ? $m['label_off'] : $m['label_on']) ?>"
                                        onclick="return confirm(<?= $e(json_encode($m['on'] ? $m['confirm_off'] : $m['confirm_on'])) ?>)"></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canDisputes && !empty($disputes)): ?>
    <section class="adm-card" id="disputes">
        <div class="adm-card-head">
            <div>
                <h2 class="adm-card-title"><?= $e(t('admin.disputes')) ?> <span class="adm-count"><?= count($disputes) ?></span></h2>
                <p class="adm-card-sub"><?= $e(t('admin.disputes_hint')) ?></p>
            </div>
        </div>
        <div class="adm-list">
            <?php foreach ($disputes as $d): ?>
                <a class="adm-row" href="<?= $u('/orders/' . (int) $d['id']) ?>">
                    <div class="adm-row-main">
                        <div class="adm-row-title truncate"><span class="subtle">#<?= (int) $d['id'] ?></span> <?= $e($d['product_title']) ?></div>
                        <div class="adm-row-sub"><?= $e($d['buyer_name']) ?> ↔ <?= $e($d['seller_name']) ?></div>
                    </div>
                    <span class="adm-link"><?= $e(t('admin.resolve')) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canProducts): ?>
    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('admin.dash_products')) ?></h2>
        </div>
        <div class="adm-stats" style="--cols:4;border:0;border-radius:0;box-shadow:none;border-bottom:1px solid var(--adm-border)">
            <div class="adm-stat">
                <div class="adm-stat-label"><?= $e(t('admin.active_lots')) ?></div>
                <div class="adm-stat-value"><?= (int) array_sum($counts) ?></div>
            </div>
            <?php $i = 0; foreach ($counts as $type => $cnt): if ($i++ >= 3) break; ?>
                <div class="adm-stat">
                    <div class="adm-stat-label truncate"><?= ProductHelper::label($type) ?></div>
                    <div class="adm-stat-value"><?= (int) $cnt ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <details>
            <summary class="adm-card-head" style="cursor:pointer;list-style:none;border-bottom:0">
                <span class="adm-card-title"><?= $e(t('admin.active_lots')) ?> <span class="adm-count"><?= count($items) ?></span></span>
                <svg class="adm-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </summary>
            <div class="adm-table-wrap" style="border-top:1px solid var(--adm-border)">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= $e(t('admin.name')) ?></th>
                            <th><?= $e(t('admin.type')) ?></th>
                            <th><?= $e(t('admin.status')) ?></th>
                            <th class="num"><?= $e(t('admin.price')) ?></th>
                            <th class="num"><?= $e(t('admin.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr class="js-row-link" data-href="<?= $u('/product/' . $item['id']) ?>">
                                <td class="subtle"><?= (int) $item['id'] ?></td>
                                <td class="strong truncate" style="max-width:280px"><?= $e($item['title']) ?></td>
                                <td><?= ProductHelper::label($item['type']) ?></td>
                                <td>
                                    <span class="adm-badge adm-badge--<?= $item['status'] === 'active' ? 'ok' : 'neutral' ?>"><span class="adm-dot"></span><?= $e($item['status']) ?></span>
                                </td>
                                <td class="num strong"><?= $e(ProductHelper::formatPrice($item)) ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="<?= $u('/product/' . $item['id']) ?>" class="adm-link"><?= $e(t('admin.open')) ?></a>
                                        <form method="post" action="<?= $u('/admin/toggle/' . $item['id']) ?>" class="adm-inline">
                                            <?= csrf_field() ?>
                                            <button class="adm-link adm-link--muted"><?= $e(t('admin.archive')) ?></button>
                                        </form>
                                        <form method="post" action="<?= $u('/admin/delete/' . $item['id']) ?>" class="adm-inline" onsubmit="return confirm(<?= $e(json_encode(t('admin.confirm_delete'))) ?>)">
                                            <?= csrf_field() ?>
                                            <button class="adm-link adm-link--danger"><?= $e(t('admin.delete')) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
    <?php elseif (!$isAdmin && !$canTickets && !$canAi && empty($disputes)): ?>
        <div class="adm-card adm-empty"><?= $e(t('admin.manager_no_access')) ?></div>
    <?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('.js-row-link').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            if (window.getSelection && String(window.getSelection())) return;
            const href = row.getAttribute('data-href');
            if (!href) return;
            if (e.metaKey || e.ctrlKey) window.open(href, '_blank');
            else window.location.href = href;
        });
    });
})();
</script>
