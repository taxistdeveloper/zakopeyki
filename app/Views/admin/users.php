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

$roleLabel = static function (string $role): string {
    return match ($role) {
        'admin' => t('nav.role_admin'),
        'manager' => t('nav.role_manager'),
        default => t('nav.role_user'),
    };
};

$roleClass = static function (string $role): string {
    return match ($role) {
        'admin' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'manager' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        default => 'bg-gray-100 text-gray-500 dark:bg-white/10',
    };
};

$filters = [
    null => t('admin.tickets_all'),
    'admin' => t('nav.role_admin'),
    'manager' => t('nav.role_manager'),
    'user' => t('nav.role_user'),
];
?>
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.users')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.users_hint')) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_users_total')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $statTotal ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_users_today')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $statToday ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_users_week')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $statWeek ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_logins_today')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $statLoginsToday ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_logins_week')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $statLoginsWeek ?></div>
        </div>
        <a href="<?= ProductHelper::url('/admin/users?access=open') ?>" class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft hover:border-emerald-400/50 transition block">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_site_access')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-emerald-700 dark:text-emerald-300"><?= $statAccess ?></div>
        </a>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/admin/users') ?>" class="flex flex-wrap gap-2">
        <?php if ($filterRole): ?>
            <input type="hidden" name="role" value="<?= htmlspecialchars($filterRole) ?>">
        <?php endif; ?>
        <?php if ($filterAccess): ?>
            <input type="hidden" name="access" value="<?= htmlspecialchars($filterAccess) ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
               placeholder="<?= htmlspecialchars(t('admin.users_search')) ?>"
               class="ui-input flex-1 min-w-[200px] h-10 px-4 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
        <button type="submit" class="h-10 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold uppercase tracking-wider transition">
            <?= htmlspecialchars(t('admin.users_find')) ?>
        </button>
    </form>

    <div class="flex flex-wrap gap-2">
        <?php foreach ($filters as $key => $label):
            $active = $filterRole === $key && !$filterAccess;
            $params = [];
            if ($key) {
                $params['role'] = $key;
            }
            if ($searchQuery !== '') {
                $params['q'] = $searchQuery;
            }
            $url = ProductHelper::url('/admin/users' . ($params ? '?' . http_build_query($params) : ''));
        ?>
            <a href="<?= $url ?>"
               class="inline-flex h-8 px-3 items-center rounded-xl text-[11px] font-semibold transition <?= $active ? 'bg-brand-600 text-white' : 'bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 text-ink-700 dark:text-gray-300 hover:border-brand-400/50' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
        <?php
        foreach (['open' => t('admin.users_filter_access'), 'closed' => t('admin.users_filter_no_access')] as $accessKey => $accessLabel):
            $active = $filterAccess === $accessKey;
            $params = ['access' => $accessKey];
            if ($searchQuery !== '') {
                $params['q'] = $searchQuery;
            }
            $url = ProductHelper::url('/admin/users?' . http_build_query($params));
        ?>
            <a href="<?= $url ?>"
               class="inline-flex h-8 px-3 items-center rounded-xl text-[11px] font-semibold transition <?= $active ? 'bg-emerald-600 text-white' : 'bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 text-ink-700 dark:text-gray-300 hover:border-brand-400/50' ?>">
                <?= htmlspecialchars($accessLabel) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5">
        <h2 class="font-display font-bold text-ink-900 dark:text-white text-sm mb-3"><?= htmlspecialchars(t('admin.user_add')) ?></h2>
        <form method="post" action="<?= ProductHelper::url('/admin/users') ?>" class="grid sm:grid-cols-2 gap-3" id="admin-create-user">
            <?= csrf_field() ?>
            <input type="text" name="name" required maxlength="100" placeholder="<?= htmlspecialchars(t('admin.user_name')) ?>"
                   class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
            <input type="email" name="email" required maxlength="150" placeholder="<?= htmlspecialchars(t('admin.user_email')) ?>"
                   class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
            <input type="text" name="login" maxlength="50" placeholder="<?= htmlspecialchars(t('admin.user_login')) ?>"
                   class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
            <input type="text" name="phone" maxlength="30" placeholder="<?= htmlspecialchars(t('admin.user_phone')) ?>"
                   class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
            <input type="password" name="password" required minlength="6" placeholder="<?= htmlspecialchars(t('admin.user_password')) ?>"
                   class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
            <select name="role" id="create-role" class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
                <option value="user"><?= htmlspecialchars(t('nav.role_user')) ?></option>
                <option value="manager"><?= htmlspecialchars(t('nav.role_manager')) ?></option>
                <option value="admin"><?= htmlspecialchars(t('nav.role_admin')) ?></option>
            </select>
            <div id="create-perms" class="sm:col-span-2 hidden space-y-2">
                <p class="text-[11px] font-semibold text-gray-500"><?= htmlspecialchars(t('admin.user_perms_hint')) ?></p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($permissionKeys as $perm): ?>
                        <label class="inline-flex items-center gap-2 h-9 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold cursor-pointer hover:border-brand-400/50">
                            <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($perm) ?>" class="rounded border-gray-300">
                            <?= htmlspecialchars(t('admin.perm_' . $perm)) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="h-10 px-5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold uppercase tracking-wider transition">
                    <?= htmlspecialchars(t('admin.user_create')) ?>
                </button>
            </div>
        </form>
    </div>

    <?php if (empty($users)): ?>
        <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('admin.users_empty')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft divide-y divide-black/[0.04] dark:divide-white/5">
            <?php foreach ($users as $u):
                $role = (string) ($u['role'] ?? 'user');
                $displayName = trim((string) ($u['name'] ?? '')) ?: ((string) ($u['login'] ?? 'User'));
                $hasAccess = $role === 'admin' || !empty($u['site_access']);
                $uid = (int) $u['id'];
            ?>
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                    <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="min-w-0 flex-1 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 flex items-center justify-center text-xs font-bold flex-shrink-0">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($displayName, 0, 1))) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-ink-900 dark:text-white truncate">
                                #<?= $uid ?> · <?= htmlspecialchars($displayName) ?>
                            </p>
                            <p class="text-[11px] text-gray-400 mt-0.5 truncate">
                                <?= htmlspecialchars((string) ($u['email'] ?? '')) ?>
                                <?php if (!empty($u['login'])): ?>
                                    · @<?= htmlspecialchars((string) $u['login']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </a>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if ($role !== 'admin' && $hasAccess): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                <?= htmlspecialchars(t('admin.users_access_badge')) ?>
                            </span>
                        <?php endif; ?>
                        <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $roleClass($role) ?>">
                            <?= htmlspecialchars($roleLabel($role)) ?>
                        </span>
                        <?php if (($u['aml_status'] ?? '') === 'AML_BLOCKED'): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                AML
                            </span>
                        <?php endif; ?>
                        <?php if ($role !== 'admin'): ?>
                            <form method="post" action="<?= ProductHelper::url('/admin/users/' . $uid . '/site-access') ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="redirect" value="list">
                                <input type="hidden" name="allow" value="<?= $hasAccess ? '0' : '1' ?>">
                                <button type="submit"
                                        class="h-8 px-2.5 rounded-lg text-[10px] font-bold transition <?= $hasAccess
                                            ? 'text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950/30'
                                            : 'text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/30' ?>">
                                    <?= htmlspecialchars($hasAccess ? t('admin.user_site_access_revoke') : t('admin.user_site_access_grant')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <span class="text-[10px] text-gray-400"><?= htmlspecialchars(substr((string) ($u['created_at'] ?? ''), 0, 10)) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
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
