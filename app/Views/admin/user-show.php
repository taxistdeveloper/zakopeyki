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

$roleLabel = match ($role) {
    'admin' => t('nav.role_admin'),
    'manager' => t('nav.role_manager'),
    default => t('nav.role_user'),
};
$roleClass = match ($role) {
    'admin' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    'manager' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    default => 'bg-gray-100 text-gray-500 dark:bg-white/10',
};
?>
<section class="max-w-2xl mx-auto fade-up pb-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-start gap-3 min-w-0">
            <a href="<?= ProductHelper::url('/admin/users') ?>" class="p-2 rounded-xl text-gray-400 hover:text-brand-600 hover:bg-black/[0.04] dark:hover:bg-white/5 transition">←</a>
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
                <h1 class="font-display text-xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars($displayName) ?></h1>
                <p class="text-[11px] text-gray-400 mt-1">#<?= $userId ?> · <?= htmlspecialchars((string) ($user['email'] ?? '')) ?></p>
                <span class="inline-flex mt-2 px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $roleClass ?>">
                    <?= htmlspecialchars($roleLabel) ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 space-y-3">
        <h2 class="font-display font-bold text-ink-900 dark:text-white text-sm"><?= htmlspecialchars(t('admin.user_info')) ?></h2>
        <dl class="grid sm:grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.user_name')) ?></dt>
                <dd class="mt-0.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars($displayName) ?></dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.user_email')) ?></dt>
                <dd class="mt-0.5 text-ink-800 dark:text-gray-200 break-all"><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.user_login')) ?></dt>
                <dd class="mt-0.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars((string) ($user['login'] ?? '—')) ?></dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.user_phone')) ?></dt>
                <dd class="mt-0.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars((string) ($user['phone'] ?? '—')) ?></dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.user_created_at')) ?></dt>
                <dd class="mt-0.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars((string) ($user['created_at'] ?? '—')) ?></dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">2FA</dt>
                <dd class="mt-0.5 text-ink-800 dark:text-gray-200"><?= !empty($user['two_factor_enabled']) ? t('admin.user_2fa_on') : t('admin.user_2fa_off') ?></dd>
            </div>
        </dl>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 space-y-3">
        <h2 class="font-display font-bold text-ink-900 dark:text-white text-sm"><?= htmlspecialchars(t('admin.user_role')) ?></h2>
        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.user_role_hint')) ?></p>
        <div class="flex flex-wrap gap-2">
            <?php if ($role !== 'admin'): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="admin">
                    <button type="submit" class="h-9 px-4 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold transition"
                            onclick="return confirm(<?= json_encode(t('admin.user_confirm_promote')) ?>)">
                        <?= htmlspecialchars(t('admin.user_make_admin')) ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($role !== 'manager'): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="manager">
                    <input type="hidden" name="permissions[]" value="tickets">
                    <button type="submit" class="h-9 px-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-white text-xs font-bold transition"
                            onclick="return confirm(<?= json_encode(t('admin.user_confirm_manager')) ?>)">
                        <?= htmlspecialchars(t('admin.user_make_manager')) ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($role !== 'user'): ?>
                <?php if ($isAdminRole && !$canDemoteAdmin): ?>
                    <p class="text-xs text-amber-600 w-full"><?= htmlspecialchars($isSelf ? t('admin.user_cannot_demote_self') : t('admin.user_last_admin')) ?></p>
                <?php else: ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="role" value="user">
                        <button type="submit" class="h-9 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold hover:border-brand-400/50 transition"
                                onclick="return confirm(<?= json_encode(t('admin.user_confirm_demote')) ?>)">
                            <?= htmlspecialchars(t('admin.user_make_user')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isManager): ?>
    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-amber-200/70 dark:border-amber-900/40 shadow-soft p-4 sm:p-5 space-y-3">
        <h2 class="font-display font-bold text-amber-800 dark:text-amber-300 text-sm"><?= htmlspecialchars(t('admin.user_perms')) ?></h2>
        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.user_perms_hint')) ?></p>
        <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/permissions') ?>" class="space-y-3">
            <?= csrf_field() ?>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($permissionKeys as $perm):
                    $on = in_array($perm, $userPermissions, true);
                ?>
                    <label class="inline-flex items-center gap-2 h-9 px-3 rounded-xl border text-xs font-semibold cursor-pointer transition <?= $on ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-200' : 'border-black/[0.08] dark:border-white/10' ?>">
                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($perm) ?>" <?= $on ? 'checked' : '' ?> class="rounded border-gray-300">
                        <?= htmlspecialchars(t('admin.perm_' . $perm)) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="h-9 px-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-white text-xs font-bold transition">
                <?= htmlspecialchars(t('admin.user_perms_save')) ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-red-200/60 dark:border-red-900/40 shadow-soft p-4 sm:p-5 space-y-3">
        <h2 class="font-display font-bold text-red-700 dark:text-red-300 text-sm"><?= htmlspecialchars(t('admin.user_danger')) ?></h2>
        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.user_delete_hint')) ?></p>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/delete') ?>"
                  onsubmit="return confirm(<?= json_encode(t('admin.user_confirm_delete')) ?>)">
                <?= csrf_field() ?>
                <button type="submit" class="h-9 px-4 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold transition">
                    <?= htmlspecialchars(t('admin.user_delete')) ?>
                </button>
            </form>
        <?php else: ?>
            <p class="text-xs text-amber-600"><?= htmlspecialchars($isSelf ? t('admin.user_cannot_delete_self') : t('admin.user_last_admin')) ?></p>
        <?php endif; ?>
    </div>
</section>
