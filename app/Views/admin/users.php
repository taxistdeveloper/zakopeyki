<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

$users = $users ?? [];
$filterRole = $filterRole ?? null;
$filterAccess = $filterAccess ?? null;
$searchQuery = $searchQuery ?? '';
$permissionKeys = $permissionKeys ?? Auth::PERMISSIONS;
$stubMode = !empty($stubMode);
$siteAccessCount = (int) ($siteAccessCount ?? 0);
$userStats = $userStats ?? [];
$statTotal = (int) ($userStats['total'] ?? $userCount ?? count($users));
$statToday = (int) ($userStats['today'] ?? 0);
$statWeek = (int) ($userStats['week'] ?? 0);
$statLoginsToday = (int) ($userStats['logins_today'] ?? 0);
$statLoginsWeek = (int) ($userStats['logins_week'] ?? 0);
$statAccess = (int) ($userStats['site_access'] ?? $siteAccessCount);
$e = static fn ($s): string => htmlspecialchars((string) $s);

$roleLabel = static function (string $role): string {
    return match ($role) {
        'admin' => t('nav.role_admin'),
        'manager' => t('nav.role_manager'),
        default => t('nav.role_user'),
    };
};

$roleClass = static function (string $role): string {
    return match ($role) {
        'admin' => 'adm-badge--info',
        default => 'adm-badge--neutral',
    };
};

$filters = [
    null => t('admin.tickets_all'),
    'admin' => t('nav.role_admin'),
    'manager' => t('nav.role_manager'),
    'user' => t('nav.role_user'),
];
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.users')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.users_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <a href="#admin-create-user" class="adm-btn adm-btn--primary"><?= $e(t('admin.user_add')) ?></a>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <div class="adm-stats" style="--cols:6">
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_users_total')) ?></div>
            <div class="adm-stat-value"><?= $statTotal ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_users_today')) ?></div>
            <div class="adm-stat-value"><?= $statToday ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_users_week')) ?></div>
            <div class="adm-stat-value"><?= $statWeek ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_logins_today')) ?></div>
            <div class="adm-stat-value"><?= $statLoginsToday ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_logins_week')) ?></div>
            <div class="adm-stat-value"><?= $statLoginsWeek ?></div>
        </div>
        <a href="<?= ProductHelper::url('/admin/users?access=open') ?>" class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.stats_site_access')) ?></div>
            <div class="adm-stat-value"><?= $statAccess ?></div>
        </a>
    </div>

    <div class="adm-toolbar">
        <div class="adm-seg">
            <?php foreach ($filters as $key => $label):
                $active = (string) $filterRole === (string) $key && !$filterAccess;
                $params = [];
                if ($key) {
                    $params['role'] = $key;
                }
                if ($searchQuery !== '') {
                    $params['q'] = $searchQuery;
                }
                $url = ProductHelper::url('/admin/users' . ($params ? '?' . http_build_query($params) : ''));
            ?>
                <a href="<?= $url ?>" class="<?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="adm-seg">
            <?php
            foreach (['open' => t('admin.users_filter_access'), 'closed' => t('admin.users_filter_no_access')] as $accessKey => $accessLabel):
                $active = $filterAccess === $accessKey;
                $params = ['access' => $accessKey];
                if ($searchQuery !== '') {
                    $params['q'] = $searchQuery;
                }
                $url = ProductHelper::url('/admin/users?' . http_build_query($params));
            ?>
                <a href="<?= $url ?>" class="<?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $e($accessLabel) ?></a>
            <?php endforeach; ?>
        </div>
        <a href="<?= ProductHelper::url('/admin/aml') ?>" class="adm-link">AML</a>
        <form method="get" action="<?= ProductHelper::url('/admin/users') ?>" class="adm-toolbar grow" style="justify-content:flex-end">
            <?php if ($filterRole): ?>
                <input type="hidden" name="role" value="<?= $e($filterRole) ?>">
            <?php endif; ?>
            <?php if ($filterAccess): ?>
                <input type="hidden" name="access" value="<?= $e($filterAccess) ?>">
            <?php endif; ?>
            <input type="search" name="q" value="<?= $e($searchQuery) ?>"
                   placeholder="<?= $e(t('admin.users_search')) ?>"
                   class="adm-input" style="min-width:220px;max-width:320px;flex:1">
            <button type="submit" class="adm-btn"><?= $e(t('admin.users_find')) ?></button>
        </form>
    </div>

    <section class="adm-card">
        <?php if (empty($users)): ?>
            <div class="adm-empty"><?= $e(t('admin.users_empty')) ?></div>
        <?php else: ?>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= $e(t('admin.user_name')) ?></th>
                            <th><?= $e(t('admin.user_role')) ?></th>
                            <th><?= $e(t('admin.user_created_at')) ?></th>
                            <th class="num"><?= $e(t('admin.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $role = (string) ($u['role'] ?? 'user');
                            $displayName = trim((string) ($u['name'] ?? '')) ?: ((string) ($u['login'] ?? 'User'));
                            $hasAccess = $role === 'admin' || !empty($u['site_access']);
                            $uid = (int) $u['id'];
                        ?>
                            <tr>
                                <td class="subtle nowrap">#<?= $uid ?></td>
                                <td style="min-width:240px">
                                    <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="strong"><?= $e($displayName) ?></a>
                                    <div class="muted truncate" style="font-size:12px;max-width:360px">
                                        <?= $e((string) ($u['email'] ?? '')) ?><?php if (!empty($u['login'])): ?> · @<?= $e((string) $u['login']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="nowrap">
                                    <span class="adm-badge <?= $roleClass($role) ?>"><?= $e($roleLabel($role)) ?></span>
                                    <?php if ($role !== 'admin' && $hasAccess): ?>
                                        <span class="adm-badge adm-badge--ok"><span class="adm-dot"></span><?= $e(t('admin.users_access_badge')) ?></span>
                                    <?php endif; ?>
                                    <?php if (($u['aml_status'] ?? '') === 'AML_BLOCKED'): ?>
                                        <span class="adm-badge adm-badge--danger"><span class="adm-dot"></span>AML</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted nowrap"><?= $e(substr((string) ($u['created_at'] ?? ''), 0, 10)) ?></td>
                                <td>
                                    <div class="actions">
                                        <?php if ($role !== 'admin'): ?>
                                            <form method="post" action="<?= ProductHelper::url('/admin/users/' . $uid . '/site-access') ?>" class="adm-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="redirect" value="list">
                                                <input type="hidden" name="allow" value="<?= $hasAccess ? '0' : '1' ?>">
                                                <button type="submit" class="adm-link<?= $hasAccess ? ' adm-link--muted' : '' ?>">
                                                    <?= $e($hasAccess ? t('admin.user_site_access_revoke') : t('admin.user_site_access_grant')) ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="adm-link"><?= $e(t('admin.open')) ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('admin.user_add')) ?></h2>
        </div>
        <form method="post" action="<?= ProductHelper::url('/admin/users') ?>" class="adm-card-body adm-form-grid" id="admin-create-user">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span class="adm-label"><?= $e(t('admin.user_name')) ?></span>
                <input type="text" name="name" required maxlength="100" placeholder="<?= $e(t('admin.user_name')) ?>" class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= $e(t('admin.user_email')) ?></span>
                <input type="email" name="email" required maxlength="150" placeholder="<?= $e(t('admin.user_email')) ?>" class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= $e(t('admin.user_login')) ?></span>
                <input type="text" name="login" maxlength="50" placeholder="<?= $e(t('admin.user_login')) ?>" class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= $e(t('admin.user_phone')) ?></span>
                <input type="text" name="phone" maxlength="30" placeholder="<?= $e(t('admin.user_phone')) ?>" class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= $e(t('admin.user_password')) ?></span>
                <input type="password" name="password" required minlength="6" placeholder="<?= $e(t('admin.user_password')) ?>" class="adm-input" autocomplete="new-password">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= $e(t('admin.user_role')) ?></span>
                <select name="role" id="create-role" class="adm-select">
                    <option value="user"><?= $e(t('nav.role_user')) ?></option>
                    <option value="manager"><?= $e(t('nav.role_manager')) ?></option>
                    <option value="admin"><?= $e(t('nav.role_admin')) ?></option>
                </select>
            </label>
            <div id="create-perms" class="span-2 hidden">
                <p class="adm-help" style="margin:0 0 8px"><?= $e(t('admin.user_perms_hint')) ?></p>
                <div style="display:flex;flex-wrap:wrap;gap:8px 18px">
                    <?php foreach ($permissionKeys as $perm): ?>
                        <label class="adm-check">
                            <input type="checkbox" name="permissions[]" value="<?= $e($perm) ?>">
                            <?= $e(t('admin.perm_' . $perm)) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="adm-form-actions span-2">
                <button type="submit" class="adm-btn"><?= $e(t('admin.user_create')) ?></button>
            </div>
        </form>
    </section>
</div>
<script>
(function () {
    var sel = document.getElementById('create-role');
    var box = document.getElementById('create-perms');
    if (!sel || !box) return;
    function sync() {
        box.classList.toggle('hidden', sel.value !== 'manager');
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
