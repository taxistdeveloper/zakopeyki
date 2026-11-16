<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

$user = $user ?? [];
$userId = (int) ($user['id'] ?? 0);
$role = (string) ($user['role'] ?? 'user');
$isAdminRole = $role === 'admin';
$isManager = $role === 'manager';
$isSelf = !empty($isSelf);
$adminCount = (int) ($adminCount ?? 0);
$userPermissions = $userPermissions ?? [];
$permissionKeys = $permissionKeys ?? Auth::PERMISSIONS;
$displayName = trim((string) ($user['name'] ?? '')) ?: ((string) ($user['login'] ?? 'User'));
$canDemoteAdmin = $isAdminRole && !$isSelf && $adminCount > 1;
$canDelete = !$isSelf && !($isAdminRole && $adminCount <= 1);
$e = static fn ($s): string => htmlspecialchars((string) $s);

$roleLabel = match ($role) {
    'admin' => t('nav.role_admin'),
    'manager' => t('nav.role_manager'),
    default => t('nav.role_user'),
};
$roleClass = match ($role) {
    'admin' => 'adm-badge--info',
    default => 'adm-badge--neutral',
};
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <nav class="adm-crumbs">
                <a href="<?= ProductHelper::url('/admin/users') ?>"><?= $e(t('admin.users')) ?></a>
                <span class="sep">/</span>
                <span><?= $e($displayName) ?></span>
            </nav>
            <h1 class="adm-title"><?= $e($displayName) ?></h1>
            <p class="adm-sub">
                #<?= $userId ?> · <?= $e((string) ($user['email'] ?? '')) ?>
                · <span class="adm-badge <?= $roleClass ?>"><?= $e($roleLabel) ?></span>
                <?php if (($user['aml_status'] ?? '') === 'AML_BLOCKED'): ?>
                    <span class="adm-badge adm-badge--danger"><span class="adm-dot"></span>AML_BLOCKED</span>
                <?php endif; ?>
            </p>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <div class="adm-grid-2" style="align-items:start">
        <div class="adm-stack">
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= $e(t('admin.user_info')) ?></h2>
                </div>
                <dl class="adm-kv">
                    <div>
                        <dt><?= $e(t('admin.user_name')) ?></dt>
                        <dd><?= $e($displayName) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.user_email')) ?></dt>
                        <dd><?= $e((string) ($user['email'] ?? '')) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.user_login')) ?></dt>
                        <dd><?= $e((string) ($user['login'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.user_phone')) ?></dt>
                        <dd><?= $e((string) ($user['phone'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.user_created_at')) ?></dt>
                        <dd><?= $e((string) ($user['created_at'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>2FA</dt>
                        <dd><?= !empty($user['two_factor_enabled']) ? t('admin.user_2fa_on') : t('admin.user_2fa_off') ?></dd>
                    </div>
                </dl>
            </section>

            <?php $amlBlocked = ($user['aml_status'] ?? '') === 'AML_BLOCKED'; ?>
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= $e(t('admin.aml')) ?></h2>
                        <p class="adm-card-sub"><?= $e(t('admin.aml_user_hint')) ?></p>
                    </div>
                    <a href="<?= ProductHelper::url('/admin/logs?action=aml&user_id=' . $userId) ?>" class="adm-link nowrap"><?= $e(t('admin.aml_open_logs')) ?></a>
                </div>
                <dl class="adm-kv">
                    <div>
                        <dt><?= $e(t('admin.aml_status')) ?></dt>
                        <dd>
                            <?php $st = (string) ($user['aml_status'] ?? ''); ?>
                            <?php if ($st === 'AML_BLOCKED'): ?>
                                <span class="adm-badge adm-badge--danger"><span class="adm-dot"></span>AML_BLOCKED</span>
                            <?php elseif ($st === 'clear'): ?>
                                <span class="adm-badge adm-badge--ok"><span class="adm-dot"></span><?= $e(t('admin.aml_badge_clear')) ?></span>
                            <?php else: ?>
                                <span class="adm-badge adm-badge--neutral"><span class="adm-dot"></span><?= $e(t('admin.aml_badge_pending')) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.aml_iin')) ?></dt>
                        <dd class="mono"><?= $e(\App\Services\AMLService::maskIin($user['iin'] ?? null)) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.aml_bin')) ?></dt>
                        <dd class="mono"><?= $e(\App\Services\AMLService::maskIin($user['bin'] ?? null)) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.aml_checked_at')) ?></dt>
                        <dd><?= $e((string) ($user['aml_checked_at'] ?? '—')) ?></dd>
                    </div>
                </dl>
                <?php
                $amlEvents = $amlEvents ?? [];
                if (!empty($amlEvents)):
                ?>
                    <div class="adm-card-head" style="border-top:1px solid var(--adm-border)">
                        <h3 class="adm-card-title"><?= $e(t('admin.aml_events')) ?></h3>
                    </div>
                    <div class="adm-list">
                        <?php foreach ($amlEvents as $ev): ?>
                            <div class="adm-row">
                                <div class="adm-row-main">
                                    <div class="adm-row-title"><?= $e(\App\Helpers\ActivityLogger::actionLabel((string) ($ev['action'] ?? ''))) ?></div>
                                    <div class="adm-row-sub"><?= $e((string) ($ev['message'] ?? '')) ?></div>
                                </div>
                                <div class="adm-row-side nowrap"><?= $e(substr((string) ($ev['created_at'] ?? ''), 0, 16)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($amlBlocked): ?>
                    <div class="adm-card-foot">
                        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/aml-clear') ?>" class="adm-inline"
                              onsubmit="return confirm(<?= $e(json_encode(t('admin.aml_unblock_confirm'))) ?>)">
                            <?= csrf_field() ?>
                            <button type="submit" class="adm-btn adm-btn--sm"><?= $e(t('admin.aml_unblock')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="adm-stack">
            <?php if (!$isAdminRole): ?>
            <?php $hasSiteAccess = !empty($user['site_access']); ?>
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= $e(t('admin.user_site_access')) ?></h2>
                        <p class="adm-card-sub"><?= $e(t('admin.user_site_access_hint')) ?></p>
                    </div>
                </div>
                <div class="adm-card-body" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
                    <span class="adm-badge adm-badge--<?= $hasSiteAccess ? 'ok' : 'neutral' ?>"><span class="adm-dot"></span><?= $e($hasSiteAccess ? t('admin.user_site_access_on') : t('admin.user_site_access_off')) ?></span>
                    <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/site-access') ?>" class="adm-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="allow" value="<?= $hasSiteAccess ? '0' : '1' ?>">
                        <button type="submit" class="adm-btn adm-btn--sm"><?= $e($hasSiteAccess ? t('admin.user_site_access_revoke') : t('admin.user_site_access_grant')) ?></button>
                    </form>
                </div>
            </section>
            <?php endif; ?>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= $e(t('admin.user_role')) ?></h2>
                        <p class="adm-card-sub"><?= $e(t('admin.user_role_hint')) ?></p>
                    </div>
                    <span class="adm-badge <?= $roleClass ?>"><?= $e($roleLabel) ?></span>
                </div>
                <div class="adm-card-body" style="display:flex;flex-wrap:wrap;gap:8px">
                    <?php if ($role !== 'admin'): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>" class="adm-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="role" value="admin">
                            <button type="submit" class="adm-btn adm-btn--sm"
                                    onclick="return confirm(<?= $e(json_encode(t('admin.user_confirm_promote'))) ?>)">
                                <?= $e(t('admin.user_make_admin')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($role !== 'manager'): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>" class="adm-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="role" value="manager">
                            <input type="hidden" name="permissions[]" value="tickets">
                            <button type="submit" class="adm-btn adm-btn--sm"
                                    onclick="return confirm(<?= $e(json_encode(t('admin.user_confirm_manager'))) ?>)">
                                <?= $e(t('admin.user_make_manager')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($role !== 'user'): ?>
                        <?php if ($isAdminRole && !$canDemoteAdmin): ?>
                            <p class="adm-help" style="margin:0;width:100%"><?= $e($isSelf ? t('admin.user_cannot_demote_self') : t('admin.user_last_admin')) ?></p>
                        <?php else: ?>
                            <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>" class="adm-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="role" value="user">
                                <button type="submit" class="adm-btn adm-btn--sm"
                                        onclick="return confirm(<?= $e(json_encode(t('admin.user_confirm_demote'))) ?>)">
                                    <?= $e(t('admin.user_make_user')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($isManager): ?>
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= $e(t('admin.user_perms')) ?></h2>
                        <p class="adm-card-sub"><?= $e(t('admin.user_perms_hint')) ?></p>
                    </div>
                </div>
                <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/permissions') ?>">
                    <?= csrf_field() ?>
                    <div class="adm-card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px 16px">
                        <?php foreach ($permissionKeys as $perm):
                            $on = in_array($perm, $userPermissions, true);
                        ?>
                            <label class="adm-check">
                                <input type="checkbox" name="permissions[]" value="<?= $e($perm) ?>" <?= $on ? 'checked' : '' ?>>
                                <?= $e(t('admin.perm_' . $perm)) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="adm-card-foot adm-form-actions">
                        <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm"><?= $e(t('admin.user_perms_save')) ?></button>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <?php if (!$isAdminRole && !$isSelf): ?>
            <?php $isBlockedUser = !empty($user['blocked_at']); ?>
            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= $e(t('admin.user_block_title')) ?></h2>
                        <p class="adm-card-sub">
                            <?php if ($isBlockedUser): ?>
                                <?= $e(t('admin.user_blocked_since', ['date' => (string) $user['blocked_at']])) ?>
                                · <?= $e((string) ($user['blocked_reason'] ?? '')) ?>
                            <?php else: ?>
                                <?= $e(t('admin.user_block_hint')) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="adm-card-body adm-stack" style="gap:10px">
                    <p class="adm-help" style="margin:0">
                        <?= $e(!empty($user['email_verified_at']) ? t('admin.user_email_verified') : t('admin.user_email_unverified')) ?>
                    </p>
                    <?php if ($isBlockedUser): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/unblock') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="adm-btn adm-btn--sm"><?= $e(t('admin.user_unblock')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/block') ?>" class="adm-toolbar"
                              onsubmit="return confirm(<?= $e(js_encode(t('admin.user_block_confirm'))) ?>)">
                            <?= csrf_field() ?>
                            <input name="reason" required minlength="3" maxlength="500"
                                   placeholder="<?= $e(t('admin.user_block_reason')) ?>"
                                   class="adm-input grow">
                            <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm"><?= $e(t('admin.user_block')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= $e(t('admin.user_danger')) ?></h2>
                        <p class="adm-card-sub"><?= $e(t('admin.user_delete_hint')) ?></p>
                    </div>
                </div>
                <div class="adm-card-body">
                    <?php if ($canDelete): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/delete') ?>"
                              onsubmit="return confirm(<?= $e(json_encode(t('admin.user_confirm_delete'))) ?>)">
                            <?= csrf_field() ?>
                            <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm"><?= $e(t('admin.user_delete')) ?></button>
                        </form>
                    <?php else: ?>
                        <p class="adm-help" style="margin:0"><?= $e($isSelf ? t('admin.user_cannot_delete_self') : t('admin.user_last_admin')) ?></p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
