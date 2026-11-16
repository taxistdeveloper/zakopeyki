<?php

use App\Helpers\ProductHelper;
use App\Models\BusinessUpgradeRequest;

$requests = $requests ?? [];
$filterStatus = $filterStatus ?? 'pending';
$pendingCount = (int) ($pendingCount ?? 0);
$e = static fn ($s): string => htmlspecialchars((string) $s);

$filters = [
    'pending' => t('admin.business_filter_pending'),
    'approved' => t('admin.business_filter_approved'),
    'rejected' => t('admin.business_filter_rejected'),
    'all' => t('admin.business_filter_all'),
];
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.business')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.business_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <form method="post" action="<?= ProductHelper::url('/admin/business/reset-limits') ?>" class="adm-inline" onsubmit="return confirm(<?= $e(json_encode(t('admin.business_reset_confirm'))) ?>)">
                <?= csrf_field() ?>
                <button type="submit" class="adm-btn"><?= $e(t('admin.business_reset_limits')) ?></button>
            </form>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php
    $businessSalesEnabled = !empty($businessSalesEnabled);
    $businessFeaturesEnabled = !empty($businessFeaturesEnabled);
    $businessAiAddonSalesFlags = is_array($businessAiAddonSalesFlags ?? null) ? $businessAiAddonSalesFlags : [];
    $aiInfographicSalesOn = !empty($businessAiAddonSalesFlags[\App\Services\Business\BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON]);
    $aiTryonSalesOn = !empty($businessAiAddonSalesFlags[\App\Services\Business\BusinessPackagePolicy::SLUG_AI_TRYON_ADDON]);

    $toggles = [
        [
            'label' => t('admin.business_sales_label'),
            'hint' => t('admin.business_sales_hint'),
            'on' => $businessSalesEnabled,
            'state' => $businessSalesEnabled ? t('admin.business_sales_on') : t('admin.business_sales_off'),
            'action' => '/admin/business/package-sales',
            'slug' => null,
            'confirm' => $businessSalesEnabled ? t('admin.business_sales_disable_confirm') : t('admin.business_sales_enable_confirm'),
            'button' => $businessSalesEnabled ? t('admin.business_sales_disable') : t('admin.business_sales_enable'),
        ],
        [
            'label' => t('admin.business_features_label'),
            'hint' => t('admin.business_features_hint'),
            'on' => $businessFeaturesEnabled,
            'state' => $businessFeaturesEnabled ? t('admin.business_features_on') : t('admin.business_features_off'),
            'action' => '/admin/business/package-features',
            'slug' => null,
            'confirm' => $businessFeaturesEnabled ? t('admin.business_features_disable_confirm') : t('admin.business_features_enable_confirm'),
            'button' => $businessFeaturesEnabled ? t('admin.business_features_disable') : t('admin.business_features_enable'),
        ],
        [
            'label' => t('admin.business_ai_infographic_sales_label'),
            'hint' => t('admin.business_ai_infographic_sales_hint'),
            'on' => $aiInfographicSalesOn,
            'state' => $aiInfographicSalesOn ? t('admin.business_ai_addon_sales_on') : t('admin.business_ai_addon_sales_off'),
            'action' => '/admin/business/ai-addon-sales',
            'slug' => \App\Services\Business\BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON,
            'confirm' => $aiInfographicSalesOn ? t('admin.business_ai_infographic_sales_disable_confirm') : t('admin.business_ai_infographic_sales_enable_confirm'),
            'button' => $aiInfographicSalesOn ? t('admin.business_ai_addon_sales_disable') : t('admin.business_ai_addon_sales_enable'),
        ],
        [
            'label' => t('admin.business_ai_tryon_sales_label'),
            'hint' => t('admin.business_ai_tryon_sales_hint'),
            'on' => $aiTryonSalesOn,
            'state' => $aiTryonSalesOn ? t('admin.business_ai_addon_sales_on') : t('admin.business_ai_addon_sales_off'),
            'action' => '/admin/business/ai-addon-sales',
            'slug' => \App\Services\Business\BusinessPackagePolicy::SLUG_AI_TRYON_ADDON,
            'confirm' => $aiTryonSalesOn ? t('admin.business_ai_tryon_sales_disable_confirm') : t('admin.business_ai_tryon_sales_enable_confirm'),
            'button' => $aiTryonSalesOn ? t('admin.business_ai_addon_sales_disable') : t('admin.business_ai_addon_sales_enable'),
        ],
    ];
    ?>
    <section class="adm-card">
        <div class="adm-table-wrap">
            <table class="adm-table">
                <tbody>
                    <?php foreach ($toggles as $tg): ?>
                    <tr>
                        <td style="min-width:260px">
                            <div class="strong"><?= $e($tg['label']) ?></div>
                            <div class="muted adm-clamp-2" style="font-size:12px;margin-top:2px;max-width:72ch" title="<?= $e($tg['hint']) ?>"><?= $e($tg['hint']) ?></div>
                        </td>
                        <td class="nowrap">
                            <span class="adm-badge adm-badge--<?= $tg['on'] ? 'ok' : 'neutral' ?>"><span class="adm-dot"></span><?= $e($tg['state']) ?></span>
                        </td>
                        <td>
                            <div class="actions">
                                <form method="post" action="<?= ProductHelper::url($tg['action']) ?>" class="adm-inline">
                                    <?= csrf_field() ?>
                                    <?php if ($tg['slug'] !== null): ?>
                                        <input type="hidden" name="package_slug" value="<?= htmlspecialchars($tg['slug'], ENT_QUOTES) ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="enabled" value="<?= $tg['on'] ? '0' : '1' ?>">
                                    <button type="submit" class="adm-switch" aria-pressed="<?= $tg['on'] ? 'true' : 'false' ?>"
                                            aria-label="<?= $e($tg['button']) ?>" title="<?= $e($tg['button']) ?>"
                                            onclick="return confirm(<?= $e(json_encode($tg['confirm'])) ?>)"></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="adm-toolbar">
        <div class="adm-seg">
            <?php foreach ($filters as $key => $label): $on = $filterStatus === $key; ?>
                <a href="<?= ProductHelper::url('/admin/business?status=' . urlencode($key)) ?>" class="<?= $on ? 'is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                    <?= $e($label) ?><?php if ($key === 'pending'): ?> <span class="adm-count<?= $pendingCount > 0 ? ' adm-count--warn' : '' ?>"><?= $pendingCount ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$requests): ?>
        <div class="adm-card adm-empty"><?= $e(t('admin.business_empty')) ?></div>
    <?php else: ?>
        <div class="adm-stack">
            <?php foreach ($requests as $req):
                $docs = BusinessUpgradeRequest::decodeDocs($req['doc_files'] ?? null);
                $status = (string) ($req['status'] ?? 'pending');
                $tone = $status === 'pending' ? 'warn' : ($status === 'approved' ? 'ok' : 'danger');
                ?>
                <article class="adm-card">
                    <div class="adm-card-head">
                        <div style="min-width:0">
                            <h2 class="adm-card-title">
                                <?= $e((string) ($req['business_name'] ?? '')) ?>
                                <span class="adm-badge adm-badge--neutral"><?= $e(strtoupper((string) ($req['entity_type'] ?? ''))) ?></span>
                            </h2>
                            <p class="adm-card-sub">
                                <?= $e((string) ($req['user_name'] ?? '')) ?> · <?= $e((string) ($req['user_email'] ?? '')) ?>
                                · БИН/ИИН <span class="mono"><?= $e((string) ($req['bin'] ?? '')) ?></span>
                            </p>
                            <?php if (!empty($req['address'])): ?>
                                <p class="adm-card-sub"><?= $e((string) $req['address']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="adm-badge adm-badge--<?= $tone ?>"><span class="adm-dot"></span><?= $e(t('admin.business_status_' . $status)) ?></span>
                    </div>

                    <?php if ($docs || ($status !== 'pending' && !empty($req['admin_note']))): ?>
                        <div class="adm-card-body adm-stack" style="gap:8px">
                            <?php if ($docs): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:6px 16px">
                                    <?php foreach ($docs as $doc): ?>
                                        <a class="adm-link" href="<?= ProductHelper::url('/admin/business/document/' . rawurlencode($doc)) ?>" target="_blank" rel="noopener"><?= $e($doc) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($status !== 'pending' && !empty($req['admin_note'])): ?>
                                <p class="muted" style="margin:0;font-size:13px"><?= $e((string) $req['admin_note']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($status === 'pending'): ?>
                        <div class="adm-card-foot adm-grid-2" style="gap:8px">
                            <form method="post" action="<?= ProductHelper::url('/admin/business/' . (int) $req['id'] . '/approve') ?>" class="adm-toolbar" style="flex-wrap:nowrap">
                                <?= csrf_field() ?>
                                <input type="text" name="note" placeholder="<?= $e(t('admin.business_note')) ?>" class="adm-input grow" style="min-width:0">
                                <button type="submit" class="adm-btn adm-btn--sm"><?= $e(t('admin.business_approve')) ?></button>
                            </form>
                            <form method="post" action="<?= ProductHelper::url('/admin/business/' . (int) $req['id'] . '/reject') ?>" class="adm-toolbar" style="flex-wrap:nowrap">
                                <?= csrf_field() ?>
                                <input type="text" name="reason" required placeholder="<?= $e(t('admin.business_reject_reason')) ?>" class="adm-input grow" style="min-width:0">
                                <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm"><?= $e(t('admin.business_reject')) ?></button>
                            </form>
                        </div>
                    <?php elseif ($status === 'approved' && ($req['user_account_type'] ?? '') === 'business'): ?>
                        <div class="adm-card-foot">
                            <form method="post" action="<?= ProductHelper::url('/admin/business/' . (int) $req['id'] . '/revoke') ?>" class="adm-toolbar" style="flex-wrap:nowrap" onsubmit="return confirm(<?= $e(json_encode(t('admin.business_revoke_confirm'))) ?>)">
                                <?= csrf_field() ?>
                                <input type="text" name="reason" required placeholder="<?= $e(t('admin.business_revoke_reason')) ?>" class="adm-input grow" style="min-width:0">
                                <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm"><?= $e(t('admin.business_revoke')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
