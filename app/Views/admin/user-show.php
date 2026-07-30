<?php
use App\Helpers\ProductHelper;

$user = $user ?? [];
$userId = (int) ($user['id'] ?? 0);
$role = (string) ($user['role'] ?? 'user');
$isAdmin = $role === 'admin';
$isSelf = !empty($isSelf);
$adminCount = (int) ($adminCount ?? 0);
$displayName = trim((string) ($user['name'] ?? '')) ?: ((string) ($user['login'] ?? 'User'));
$canDemote = $isAdmin && !$isSelf && $adminCount > 1;
$canDelete = !$isSelf && !($isAdmin && $adminCount <= 1);
?>
<section class="max-w-2xl mx-auto fade-up pb-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-start gap-3 min-w-0">
            <a href="<?= ProductHelper::url('/admin/users') ?>" class="p-2 rounded-xl text-gray-400 hover:text-brand-600 hover:bg-black/[0.04] dark:hover:bg-white/5 transition">←</a>
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
                <h1 class="font-display text-xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars($displayName) ?></h1>
                <p class="text-[11px] text-gray-400 mt-1">#<?= $userId ?> · <?= htmlspecialchars((string) ($user['email'] ?? '')) ?></p>
                <span class="inline-flex mt-2 px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $isAdmin ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-gray-100 text-gray-500 dark:bg-white/10' ?>">
                    <?= htmlspecialchars($isAdmin ? t('nav.role_admin') : t('nav.role_user')) ?>
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
            <?php if (!empty($user['google_id'])): ?>
                <div class="sm:col-span-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Google</dt>
                    <dd class="mt-0.5 text-ink-800 dark:text-gray-200 text-xs break-all"><?= htmlspecialchars((string) $user['google_id']) ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 space-y-3">
        <h2 class="font-display font-bold text-ink-900 dark:text-white text-sm"><?= htmlspecialchars(t('admin.user_role')) ?></h2>
        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.user_role_hint')) ?></p>
        <div class="flex flex-wrap gap-2">
            <?php if (!$isAdmin): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="admin">
                    <button type="submit" class="h-9 px-4 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold transition"
                            onclick="return confirm(<?= json_encode(t('admin.user_confirm_promote')) ?>)">
                        <?= htmlspecialchars(t('admin.user_make_admin')) ?>
                    </button>
                </form>
            <?php elseif ($canDemote): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/users/' . $userId . '/role') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="user">
                    <button type="submit" class="h-9 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold hover:border-brand-400/50 transition"
                            onclick="return confirm(<?= json_encode(t('admin.user_confirm_demote')) ?>)">
                        <?= htmlspecialchars(t('admin.user_make_user')) ?>
                    </button>
                </form>
            <?php else: ?>
                <p class="text-xs text-amber-600"><?= htmlspecialchars($isSelf ? t('admin.user_cannot_demote_self') : t('admin.user_last_admin')) ?></p>
            <?php endif; ?>
        </div>
    </div>

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
