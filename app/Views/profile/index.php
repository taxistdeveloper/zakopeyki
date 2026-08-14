<?php
use App\Helpers\ProductHelper;
use App\Helpers\AvatarHelper;
use App\Helpers\IconHelper;
use App\Models\Bonus;

$user = $user ?? [];
$tab = $tab ?? 'personal';
$avatarUrl = AvatarHelper::url($user);

$first = $user['first_name'] ?? '';
$last = $user['last_name'] ?? '';
if ($first === '' && !empty($user['name'])) {
    $parts = preg_split('/\s+/', trim($user['name']), 2);
    $first = $parts[0] ?? '';
    $last = $parts[1] ?? $last;
}
$login = $user['login'] ?? '';
if ($login === '' && !empty($user['email'])) {
    $login = strstr($user['email'], '@', true) ?: '';
}

$tabs = [
    'personal' => ['label' => t('profile.tab_personal'), 'icon' => 'user'],
    'photo' => ['label' => t('profile.tab_photo'), 'icon' => 'camera'],
    'bio' => ['label' => t('profile.tab_bio'), 'icon' => 'file'],
    'reviews' => ['label' => t('profile.tab_reviews'), 'icon' => 'star'],
    'notifications' => ['label' => t('profile.tab_notifications'), 'icon' => 'bell'],
    'password' => ['label' => t('profile.tab_password'), 'icon' => 'lock'],
    'favorites' => ['label' => t('profile.tab_favorites'), 'icon' => 'heart'],
    'subscriptions' => ['label' => t('profile.tab_subscriptions'), 'icon' => 'users'],
    'referral' => ['label' => t('profile.tab_referral'), 'icon' => 'gift'],
    'lots' => ['label' => t('profile.tab_lots'), 'icon' => 'package'],
];

$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="max-w-5xl mx-auto space-y-5 pb-8">
    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('profile.eyebrow')) ?></p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white tracking-tight"><?= htmlspecialchars(t('profile.title')) ?></h1>
        </div>
        <?php if ($avatarUrl || !empty($user['name'])): ?>
            <div class="hidden sm:flex items-center gap-3">
                <?= AvatarHelper::html($user, 'w-11 h-11', 'text-sm', 'rounded-2xl') ?>
                <div class="text-right">
                    <div class="text-sm font-semibold"><?= htmlspecialchars($user['name'] ?? '') ?></div>
                    <div class="text-[11px] text-gray-400">@<?= htmlspecialchars($login ?: 'user') ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold shadow-sm"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-600 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft backdrop-blur">
        <div class="p-3 sm:p-4 border-b border-black/[0.05] dark:border-white/10 bg-gradient-to-b from-brand-50/40 to-transparent dark:from-brand-500/5">
            <div class="flex flex-wrap gap-1.5 p-1 rounded-2xl bg-black/[0.03] dark:bg-white/[0.04]">
                <?php foreach ($tabs as $key => $meta):
                    $active = $tab === $key;
                ?>
                    <a href="<?= ProductHelper::url('/profile?tab=' . $key) ?>"
                       class="inline-flex items-center gap-2 px-3 py-2.5 text-xs sm:text-[13px] font-semibold whitespace-nowrap rounded-xl transition shrink-0
                       <?= $active
                           ? 'bg-white dark:bg-ink-800 text-ink-900 dark:text-white shadow-sm'
                           : 'text-gray-500 hover:text-ink-800 dark:hover:text-gray-200' ?>">
                        <span class="opacity-80"><?= IconHelper::svg($meta['icon'], 'w-3.5 h-3.5') ?></span>
                        <span><?= $meta['label'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="p-5 sm:p-8">
            <?php if ($tab === 'personal'): ?>
                <div class="mb-7">
                    <h2 class="font-display text-xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('profile.tab_personal')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.personal_hint')) ?></p>
                </div>

                <form method="post" action="<?= ProductHelper::url('/profile/personal') ?>" class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.first_name')) ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($first) ?>" class="<?= $input ?>">
                        </div>
                        <div>
                            <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.last_name')) ?></label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($last) ?>" class="<?= $input ?>">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.login')) ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="login" required value="<?= htmlspecialchars($login) ?>" pattern="[A-Za-z0-9_]+" class="<?= $input ?>">
                        <p class="text-xs text-gray-400 mt-1.5"><?= htmlspecialchars(t('profile.login_hint')) ?></p>
                    </div>

                    <div class="rounded-2xl border border-black/[0.08] dark:border-white/10 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-ink-50/60 dark:bg-white/[0.03]">
                        <div>
                            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Email</div>
                            <div class="flex items-center gap-2 text-sm font-semibold text-ink-900 dark:text-white">
                                <?= htmlspecialchars($user['email'] ?? '') ?>
                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-500 text-white text-[10px]">✓</span>
                            </div>
                        </div>
                        <span class="text-xs text-gray-400 font-medium"><?= htmlspecialchars(t('profile.email_verified')) ?></span>
                    </div>

                    <div>
                        <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.phone')) ?></label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+7..." class="<?= $input ?>">
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4 border-t border-black/[0.05] dark:border-white/10">
                        <p class="text-xs text-gray-400"><span class="text-red-500">*</span> <?= htmlspecialchars(t('profile.required_note')) ?></p>
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition shadow-soft">
                            <?= htmlspecialchars(t('profile.save_changes')) ?>
                        </button>
                    </div>
                </form>

            <?php elseif ($tab === 'photo'): ?>
                <div class="mb-7 text-center sm:text-left">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.photo_title')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.photo_hint')) ?></p>
                </div>
                <form method="post" action="<?= ProductHelper::url('/profile/avatar') ?>" enctype="multipart/form-data" class="flex flex-col items-center gap-5 py-6">
                    <label class="relative group cursor-pointer">
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" onchange="this.form.submit()">
                        <div class="w-40 h-40 rounded-[2rem] overflow-hidden border-[3px] border-brand-400/60 bg-brand-50 dark:bg-white/5 flex items-center justify-center shadow-lift ring-4 ring-brand-100/50 dark:ring-brand-500/10">
                            <?php if ($avatarUrl): ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars(t('profile.photo_title')) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <span class="text-5xl font-display font-bold text-brand-500/70"><?= htmlspecialchars(AvatarHelper::initial($user)) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="absolute inset-0 rounded-[2rem] bg-ink-900/55 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold uppercase tracking-wide"><?= htmlspecialchars(t('profile.change')) ?></span>
                    </label>
                    <p class="text-xs text-gray-400"><?= htmlspecialchars(t('profile.photo_formats')) ?></p>
                </form>

            <?php elseif ($tab === 'bio'): ?>
                <div class="mb-7">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_bio')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.bio_hint')) ?></p>
                </div>
                <form method="post" action="<?= ProductHelper::url('/profile/bio') ?>" class="space-y-5">
                    <textarea name="bio" rows="6" maxlength="2000" placeholder="<?= htmlspecialchars(t('profile.bio_placeholder')) ?>"
                              class="ui-input w-full p-4 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                    <div class="flex justify-end">
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition"><?= htmlspecialchars(t('profile.save')) ?></button>
                    </div>
                </form>

            <?php elseif ($tab === 'reviews'): ?>
                <?php
                $reviews = $reviews ?? [];
                $reviewStats = $reviewStats ?? ['avg' => 0, 'count' => 0];
                ?>
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_reviews')) ?></h2>
                        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.reviews_hint')) ?></p>
                    </div>
                    <?php if (($reviewStats['count'] ?? 0) > 0): ?>
                        <div class="flex items-center gap-2 rounded-2xl bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 px-3.5 py-2">
                            <span class="text-amber-500"><?= IconHelper::star('w-4 h-4', true) ?></span>
                            <span class="font-display font-bold text-sm"><?= htmlspecialchars(number_format((float) $reviewStats['avg'], 1)) ?></span>
                            <span class="text-xs text-gray-400"><?= htmlspecialchars(t('reviews.count', ['n' => (string) (int) $reviewStats['count']])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (empty($reviews)): ?>
                    <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm"><?= htmlspecialchars(t('profile.no_reviews')) ?></div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($reviews as $r): ?>
                            <?php
                            $authorUser = [
                                'name' => $r['author_name'] ?? '',
                                'avatar' => $r['author_avatar'] ?? null,
                                'avatar_file' => $r['author_avatar_file'] ?? null,
                            ];
                            $roleLabel = ($r['role'] ?? '') === 'as_seller'
                                ? t('reviews.as_seller')
                                : t('reviews.as_buyer');
                            ?>
                            <article class="rounded-2xl border border-black/[0.06] dark:border-white/10 px-4 py-4 space-y-2">
                                <div class="flex items-start gap-3">
                                    <?= AvatarHelper::html($authorUser, 'w-10 h-10', 'text-sm') ?>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span class="font-semibold text-sm"><?= htmlspecialchars($r['author_name'] ?? '') ?></span>
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars($roleLabel) ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 mt-1">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span class="<?= $i <= (int) $r['rating'] ? 'text-amber-500' : 'text-gray-300 dark:text-gray-600' ?>">
                                                    <?= IconHelper::star('w-3.5 h-3.5', $i <= (int) $r['rating']) ?>
                                                </span>
                                            <?php endfor; ?>
                                            <span class="text-[11px] text-gray-400 ml-1"><?= htmlspecialchars(date('d.m.Y', strtotime((string) $r['created_at']))) ?></span>
                                        </div>
                                    </div>
                                    <?php if (!empty($r['order_id'])): ?>
                                        <a href="<?= ProductHelper::url('/orders/' . (int) $r['order_id']) ?>" class="text-[11px] font-semibold text-brand-600 hover:underline flex-shrink-0">#<?= (int) $r['order_id'] ?></a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($r['body'])): ?>
                                    <p class="text-sm text-ink-700 dark:text-gray-300 leading-relaxed"><?= nl2br(htmlspecialchars($r['body'])) ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'notifications'): ?>
                <div class="mb-6 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_notifications')) ?></h2>
                        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.notifications_hint')) ?></p>
                    </div>
                    <?php if (!empty($notifications)): ?>
                        <form method="post" action="<?= ProductHelper::url('/notifications/clear') ?>" class="inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(t('profile.clear')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm"><?= htmlspecialchars(t('profile.no_notifications')) ?></div>
                <?php else: ?>
                    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 overflow-hidden divide-y divide-black/[0.04] dark:divide-white/5">
                        <?php foreach ($notifications as $n): ?>
                            <div class="px-4 py-3.5 text-sm <?= empty($n['is_read']) ? 'bg-brand-50/50 font-medium' : 'text-gray-600 dark:text-gray-300' ?>">
                                <?= htmlspecialchars($n['message']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'password'): ?>
                <?php
                $twoFactorEnabled = !empty($user['two_factor_enabled']) && !empty($user['two_factor_secret']);
                $twoFactorSetup = $twoFactorSetup ?? null;
                $recoveryCodes = $recoveryCodes ?? null;
                ?>
                <div class="mb-6 flex items-start gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-ink-900 text-white flex items-center justify-center"><?= IconHelper::svg('lock', 'w-5 h-5') ?></div>
                    <div>
                        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.change_password')) ?></h2>
                        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.change_password_hint')) ?></p>
                    </div>
                </div>
                <div class="mb-5 rounded-2xl border border-sky-200/80 bg-sky-50 text-sky-900 text-sm px-4 py-3.5 leading-relaxed">
                    <?= htmlspecialchars(t('profile.password_info')) ?>
                </div>
                <form method="post" action="<?= ProductHelper::url('/profile/password') ?>" class="space-y-5">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.current_password')) ?></label>
                        <input type="password" name="current_password" autocomplete="current-password" class="<?= $input ?>">
                        <p class="text-xs text-gray-400 mt-1.5"><?= htmlspecialchars(t('profile.current_password_hint')) ?></p>
                    </div>
                    <div>
                        <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.new_password')) ?> <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="password" id="pass1" required minlength="8" class="<?= $input ?> pr-11">
                            <button type="button" onclick="togglePass('pass1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-ink-800"><?= IconHelper::svg('eye', 'w-4 h-4') ?></button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5"><?= htmlspecialchars(t('profile.min_8')) ?></p>
                    </div>
                    <div>
                        <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.confirm_password')) ?> <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="password_confirm" id="pass2" required minlength="8" class="<?= $input ?> pr-11">
                            <button type="button" onclick="togglePass('pass2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-ink-800"><?= IconHelper::svg('eye', 'w-4 h-4') ?></button>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4 border-t border-black/[0.05]">
                        <p class="text-xs text-gray-400"><span class="text-red-500">*</span> <?= htmlspecialchars(t('profile.required_note')) ?></p>
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition"><?= htmlspecialchars(t('profile.change_password')) ?></button>
                    </div>
                </form>
                <script>
                function togglePass(id, btn) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.type = el.type === 'password' ? 'text' : 'password';
                }
                </script>

                <div class="mt-10 pt-8 border-t border-black/[0.08] dark:border-white/10">
                    <div class="mb-5 flex items-start gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-brand-500 text-white flex items-center justify-center">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
                        </div>
                        <div>
                            <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.two_factor_title')) ?></h2>
                            <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.two_factor_hint')) ?></p>
                        </div>
                    </div>

                    <?php if (!empty($recoveryCodes)): ?>
                        <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 text-amber-950 px-4 py-4">
                            <p class="text-sm font-semibold mb-2"><?= htmlspecialchars(t('profile.two_factor_recovery_once')) ?></p>
                            <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
                                <?php foreach ($recoveryCodes as $rc): ?>
                                    <li class="bg-white/80 rounded-xl px-3 py-2 text-center tracking-wider"><?= htmlspecialchars($rc) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($twoFactorEnabled): ?>
                        <div class="mb-4 rounded-2xl border border-emerald-200/80 bg-emerald-50 text-emerald-900 text-sm px-4 py-3.5">
                            <?= htmlspecialchars(t('profile.two_factor_on')) ?>
                        </div>
                        <form method="post" action="<?= ProductHelper::url('/profile/2fa/disable') ?>" class="space-y-4 max-w-md">
                            <?= csrf_field() ?>
                            <?php if (!empty($user['password'])): ?>
                                <div>
                                    <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.current_password')) ?></label>
                                    <input type="password" name="password" required autocomplete="current-password" class="<?= $input ?>">
                                </div>
                            <?php endif; ?>
                            <div>
                                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.two_factor_code')) ?></label>
                                <input type="text" name="code" required autocomplete="one-time-code" maxlength="19" class="<?= $input ?>" placeholder="000000">
                            </div>
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition">
                                <?= htmlspecialchars(t('profile.two_factor_disable')) ?>
                            </button>
                        </form>
                    <?php elseif ($twoFactorSetup): ?>
                        <div class="space-y-5 max-w-md">
                            <p class="text-sm text-gray-500 leading-relaxed"><?= htmlspecialchars(t('profile.two_factor_scan')) ?></p>
                            <div class="flex justify-center">
                                <img src="<?= htmlspecialchars($twoFactorSetup['qr']) ?>" alt="QR" width="200" height="200" class="rounded-2xl border border-black/10 bg-white p-2">
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 mb-1.5"><?= htmlspecialchars(t('profile.two_factor_manual')) ?></p>
                                <code class="block text-sm font-mono tracking-widest bg-gray-50 dark:bg-white/5 rounded-xl px-4 py-3 break-all"><?= htmlspecialchars($twoFactorSetup['secret']) ?></code>
                            </div>
                            <form method="post" action="<?= ProductHelper::url('/profile/2fa/confirm') ?>" class="space-y-4">
                                <?= csrf_field() ?>
                                <div>
                                    <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.two_factor_code')) ?></label>
                                    <input type="text" name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="8" class="<?= $input ?>" placeholder="000000">
                                </div>
                                <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition">
                                    <?= htmlspecialchars(t('profile.two_factor_confirm')) ?>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 mb-4 leading-relaxed"><?= htmlspecialchars(t('profile.two_factor_off')) ?></p>
                        <form method="post" action="<?= ProductHelper::url('/profile/2fa/setup') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition">
                                <?= htmlspecialchars(t('profile.two_factor_enable')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="mt-10 pt-8 border-t border-red-200/60 dark:border-red-900/40">
                    <div class="mb-5 flex items-start gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-red-600 text-white flex items-center justify-center">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div>
                            <h2 class="font-display text-xl font-bold text-red-600 dark:text-red-400"><?= htmlspecialchars(t('profile.delete_account')) ?></h2>
                            <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.delete_account_hint')) ?></p>
                        </div>
                    </div>
                    <div class="rounded-[22px] border border-red-200 dark:border-red-900/50 bg-red-50/60 dark:bg-red-950/20 p-5 sm:p-6 space-y-4">
                        <form method="post" action="<?= ProductHelper::url('/profile/delete') ?>" class="space-y-4"
                              onsubmit="return confirm(<?= json_encode(t('profile.confirm_delete_account')) ?>)">
                            <div>
                                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.current_password')) ?> <span class="text-red-500">*</span></label>
                                <input type="password" name="password" required autocomplete="current-password" class="<?= $input ?>">
                            </div>
                            <div>
                                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.type_delete')) ?> <span class="font-bold text-red-600"><?= htmlspecialchars(t('profile.delete_word')) ?></span> <span class="text-red-500">*</span></label>
                                <input type="text" name="confirm_text" required placeholder="<?= htmlspecialchars(t('profile.delete_word')) ?>" autocomplete="off" class="<?= $input ?>">
                            </div>
                            <button type="submit" class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition">
                                <?= htmlspecialchars(t('profile.delete_forever')) ?>
                            </button>
                        </form>
                    </div>
                </div>

            <?php elseif ($tab === 'favorites'): ?>
                <?php
                $favorites = $favorites ?? [];
                ?>
                <div class="mb-6">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_favorites')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.favorites_hint')) ?></p>
                </div>
                <?php if (empty($favorites)): ?>
                    <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
                        <?= htmlspecialchars(t('profile.favorites_empty')) ?>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-favorites-grid>
                        <?php foreach ($favorites as $item) {
                            \App\Core\View::partial('partials/product-card', [
                                'item' => $item,
                                'favorited' => true,
                            ]);
                        } ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'subscriptions'): ?>
                <?php
                $followingUsers = $followingUsers ?? [];
                $followerUsers = $followerUsers ?? [];
                $followingIds = array_map('intval', $followingIds ?? []);
                $subSection = $_GET['sub'] ?? 'following';
                if (!in_array($subSection, ['following', 'followers'], true)) {
                    $subSection = 'following';
                }
                ?>
                <div class="mb-6">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_subscriptions')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.subscriptions_hint')) ?></p>
                </div>

                <div class="flex gap-1.5 p-1 rounded-2xl bg-black/[0.03] dark:bg-white/[0.04] mb-6 max-w-md">
                    <a href="<?= ProductHelper::url('/profile?tab=subscriptions&sub=following') ?>"
                       class="flex-1 text-center px-3 py-2.5 text-xs sm:text-[13px] font-semibold rounded-xl transition <?= $subSection === 'following' ? 'bg-white dark:bg-ink-800 text-ink-900 dark:text-white shadow-sm' : 'text-gray-500 hover:text-ink-800 dark:hover:text-gray-200' ?>">
                        <?= htmlspecialchars(t('profile.subscriptions_following')) ?>
                        <span class="text-gray-400 font-normal">(<?= count($followingUsers) ?>)</span>
                    </a>
                    <a href="<?= ProductHelper::url('/profile?tab=subscriptions&sub=followers') ?>"
                       class="flex-1 text-center px-3 py-2.5 text-xs sm:text-[13px] font-semibold rounded-xl transition <?= $subSection === 'followers' ? 'bg-white dark:bg-ink-800 text-ink-900 dark:text-white shadow-sm' : 'text-gray-500 hover:text-ink-800 dark:hover:text-gray-200' ?>">
                        <?= htmlspecialchars(t('profile.subscriptions_followers')) ?>
                        <span class="text-gray-400 font-normal">(<?= count($followerUsers) ?>)</span>
                    </a>
                </div>

                <?php
                $list = $subSection === 'followers' ? $followerUsers : $followingUsers;
                $emptyText = $subSection === 'followers'
                    ? t('profile.subscriptions_followers_empty')
                    : t('profile.subscriptions_following_empty');
                ?>
                <?php if ($list === []): ?>
                    <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
                        <?= htmlspecialchars($emptyText) ?>
                    </div>
                <?php else: ?>
                    <div class="space-y-2" data-subscriptions-list data-sub-section="<?= htmlspecialchars($subSection) ?>">
                        <?php foreach ($list as $person):
                            $personId = (int) ($person['id'] ?? 0);
                            $personLogin = (string) ($person['login'] ?? '');
                            if ($personLogin === '' && !empty($person['email'])) {
                                $personLogin = (string) (strstr((string) $person['email'], '@', true) ?: '');
                            }
                            $iFollowThem = in_array($personId, $followingIds, true);
                            ?>
                            <div class="flex items-center gap-3 p-3 sm:p-4 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-ink-50/50 dark:bg-white/[0.03]" data-user-row="<?= $personId ?>">
                                <button type="button" class="seller-profile-trigger shrink-0" data-seller-id="<?= $personId ?>" aria-label="<?= htmlspecialchars($person['name'] ?? '') ?>">
                                    <?= AvatarHelper::html($person, 'w-12 h-12', 'text-base', 'rounded-2xl') ?>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button type="button" class="seller-profile-trigger text-left block w-full" data-seller-id="<?= $personId ?>">
                                        <span class="block text-sm font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($person['name'] ?? '') ?></span>
                                        <?php if ($personLogin !== ''): ?>
                                            <span class="block text-xs text-gray-400 truncate">@<?= htmlspecialchars($personLogin) ?></span>
                                        <?php endif; ?>
                                    </button>
                                </div>
                                <div class="shrink-0">
                                    <?php if ($subSection === 'following' || $iFollowThem): ?>
                                        <button type="button"
                                                class="follow-btn inline-flex items-center justify-center h-9 px-3.5 rounded-xl font-display font-bold text-[10px] uppercase tracking-wider transition bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-white hover:bg-ink-200 dark:hover:bg-white/15"
                                                data-user-id="<?= $personId ?>"
                                                data-following="1"
                                                aria-pressed="true">
                                            <?= htmlspecialchars(t('seller.unsubscribe')) ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                                class="follow-btn inline-flex items-center justify-center h-9 px-3.5 rounded-xl font-display font-bold text-[10px] uppercase tracking-wider transition bg-brand-500 hover:bg-brand-600 text-white shadow-sm"
                                                data-user-id="<?= $personId ?>"
                                                data-following="0"
                                                aria-pressed="false">
                                            <?= htmlspecialchars(t('seller.subscribe')) ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'referral'): ?>
                <?php
                $referralUrl = $referralUrl ?? '';
                $referralCode = $referralCode ?? '';
                $referralCount = (int) ($referralCount ?? 0);
                $referralUsers = $referralUsers ?? [];
                $refReward = Bonus::AMOUNT_REFERRAL;
                ?>
                <div class="mb-6">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_referral')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.referral_hint', [
                        'amount' => Bonus::format($refReward),
                    ])) ?></p>
                </div>

                <div class="rounded-[24px] border border-amber-200/70 dark:border-amber-800/40 bg-amber-50/60 dark:bg-amber-950/20 p-5 sm:p-6 space-y-4 mb-6">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-700/70 dark:text-amber-300/70"><?= htmlspecialchars(t('profile.referral_link')) ?></p>
                        <div class="mt-2 flex flex-col sm:flex-row gap-2">
                            <input id="referral-link-input" type="text" readonly value="<?= htmlspecialchars($referralUrl) ?>"
                                   class="<?= $input ?> font-mono text-xs sm:text-sm">
                            <button type="button" id="referral-copy-btn"
                                    class="shrink-0 inline-flex items-center justify-center px-4 py-2.5 rounded-2xl bg-ink-900 hover:bg-ink-800 text-white font-display font-bold text-xs uppercase tracking-wider transition">
                                <?= htmlspecialchars(t('profile.referral_copy')) ?>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-2"><?= htmlspecialchars(t('profile.referral_code_label')) ?>: <span class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars($referralCode) ?></span></p>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300"><?= htmlspecialchars(t('profile.referral_reward', [
                        'amount' => Bonus::format($refReward),
                    ])) ?></p>
                    <p class="text-sm font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('profile.referral_stats', [
                        'count' => $referralCount,
                    ])) ?></p>
                </div>

                <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden">
                    <div class="px-5 py-4 border-b border-black/[0.05] dark:border-white/10">
                        <h3 class="font-display font-bold"><?= htmlspecialchars(t('profile.referral_list')) ?></h3>
                    </div>
                    <?php if (empty($referralUsers)): ?>
                        <p class="px-5 py-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('profile.referral_empty')) ?></p>
                    <?php else: ?>
                        <ul class="divide-y divide-black/[0.04] dark:divide-white/5">
                            <?php foreach ($referralUsers as $refUser):
                                $refId = (int) ($refUser['id'] ?? 0);
                                $refName = (string) ($refUser['name'] ?? '');
                            ?>
                            <li class="px-5 py-3.5 flex items-center gap-3">
                                <button type="button" class="seller-profile-trigger shrink-0" data-seller-id="<?= $refId ?>" aria-label="<?= htmlspecialchars($refName) ?>">
                                    <img src="<?= htmlspecialchars(AvatarHelper::url($refUser)) ?>" alt="" class="w-10 h-10 rounded-xl object-cover">
                                </button>
                                <div class="min-w-0 flex-1">
                                    <button type="button" class="seller-profile-trigger text-left block w-full" data-seller-id="<?= $refId ?>">
                                        <p class="text-sm font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($refName) ?></p>
                                        <?php if (!empty($refUser['login'])): ?>
                                            <p class="text-xs text-gray-400">@<?= htmlspecialchars((string) $refUser['login']) ?></p>
                                        <?php endif; ?>
                                    </button>
                                </div>
                                <p class="text-[11px] text-gray-400 shrink-0"><?= htmlspecialchars((string) ($refUser['created_at'] ?? '')) ?></p>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <script>
                (function () {
                    var btn = document.getElementById('referral-copy-btn');
                    var input = document.getElementById('referral-link-input');
                    if (!btn || !input) return;
                    btn.addEventListener('click', function () {
                        var text = input.value;
                        var done = function () {
                            var prev = btn.textContent;
                            btn.textContent = <?= json_encode(t('profile.referral_copied')) ?>;
                            setTimeout(function () { btn.textContent = prev; }, 1600);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(done).catch(function () {
                                input.select();
                                try { document.execCommand('copy'); } catch (e) {}
                                done();
                            });
                        } else {
                            input.select();
                            try { document.execCommand('copy'); } catch (e) {}
                            done();
                        }
                    });
                })();
                </script>

            <?php elseif ($tab === 'lots'): ?>
                <?php $editing = $editProduct ?? null; ?>
                <div class="mb-6">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars($editing ? t('profile.edit_lot') : t('profile.create_lot')) ?></h2>
                </div>
                <form method="post" action="<?= $editing ? ProductHelper::url('/profile/lots/' . $editing['id'] . '/update') : ProductHelper::url('/profile/store') ?>" enctype="multipart/form-data" class="space-y-4 mb-8 p-5 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-brand-50/30 dark:bg-white/[0.03]">
                    <?php $noPriceTypes = ['free', 'exchange', 'service']; ?>
                    <?php
                    $productTypesWithCategory = ProductHelper::PRODUCT_TYPES_WITH_CATEGORY;
                    $categoryTree = $productCategoryTree ?? ProductHelper::PRODUCT_CATEGORY_TREE;
                    $currentType = $editing['type'] ?? '';
                    if ($currentType === '') {
                        $pref = $prefLotType ?? '';
                        $currentType = isset(ProductHelper::TYPES[$pref]) ? $pref : 'used';
                    }
                    [$currentParent, $currentChild] = ProductHelper::parseCategory($editing['category'] ?? null);
                    $showCategory = in_array($currentType, $productTypesWithCategory, true);
                    ?>
                    <?php
                    $typePalette = [
                        'used' => ['idle' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300', 'on' => 'bg-orange-500 text-white', 'ring' => 'border-orange-400 bg-orange-50/80 dark:bg-orange-500/10'],
                        'new' => ['idle' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300', 'on' => 'bg-blue-600 text-white', 'ring' => 'border-blue-400 bg-blue-50/80 dark:bg-blue-500/10'],
                        'auction' => ['idle' => 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300', 'on' => 'bg-red-500 text-white', 'ring' => 'border-red-400 bg-red-50/80 dark:bg-red-500/10'],
                        'free' => ['idle' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300', 'on' => 'bg-sky-500 text-white', 'ring' => 'border-sky-400 bg-sky-50/80 dark:bg-sky-500/10'],
                        'exchange' => ['idle' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300', 'on' => 'bg-indigo-500 text-white', 'ring' => 'border-indigo-400 bg-indigo-50/80 dark:bg-indigo-500/10'],
                        'service' => ['idle' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300', 'on' => 'bg-emerald-500 text-white', 'ring' => 'border-emerald-400 bg-emerald-50/80 dark:bg-emerald-500/10'],
                        'gig' => ['idle' => 'bg-teal-50 text-teal-600 dark:bg-teal-500/15 dark:text-teal-300', 'on' => 'bg-teal-600 text-white', 'ring' => 'border-teal-400 bg-teal-50/80 dark:bg-teal-500/10'],
                        'course' => ['idle' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300', 'on' => 'bg-blue-500 text-white', 'ring' => 'border-blue-400 bg-blue-50/80 dark:bg-blue-500/10'],
                    ];
                    $selectTrigger = $input . ' flex items-center justify-between gap-2 text-left pr-3 cursor-pointer';
                    ?>
                    <div>
                        <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-2"><?= htmlspecialchars(t('profile.type')) ?></label>
                        <select name="type" id="lot-type" class="hidden">
                            <?php foreach ($types as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $currentType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="lot-type-cards" class="grid grid-cols-2 sm:grid-cols-4 gap-2" role="listbox" aria-label="<?= htmlspecialchars(t('profile.type')) ?>">
                            <?php foreach ($types as $key => $label):
                                $active = $currentType === $key;
                                $palette = $typePalette[$key] ?? ['idle' => 'bg-brand-50 text-brand-600', 'on' => 'bg-brand-500 text-white', 'ring' => 'border-brand-400 bg-brand-50'];
                            ?>
                                <button type="button"
                                        role="option"
                                        aria-selected="<?= $active ? 'true' : 'false' ?>"
                                        data-type="<?= htmlspecialchars($key) ?>"
                                        data-idle="<?= htmlspecialchars($palette['idle']) ?>"
                                        data-on="<?= htmlspecialchars($palette['on']) ?>"
                                        data-ring="<?= htmlspecialchars($palette['ring']) ?>"
                                        class="lot-type-card group relative flex flex-col items-center gap-2 px-2.5 py-3 rounded-2xl border text-center transition
                                               <?= $active
                                                   ? $palette['ring'] . ' shadow-soft'
                                                   : 'border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.04] hover:border-brand-300/70 hover:shadow-sm' ?>">
                                    <span class="lot-type-icon w-10 h-10 rounded-xl flex items-center justify-center transition <?= $active ? $palette['on'] : $palette['idle'] ?>">
                                        <?= ProductHelper::icon($key, 'w-[18px] h-[18px]') ?>
                                    </span>
                                    <span class="text-[11px] sm:text-xs font-semibold leading-tight text-ink-800 dark:text-gray-200"><?= htmlspecialchars($label) ?></span>
                                    <span class="lot-type-check absolute top-1.5 right-1.5 w-4 h-4 rounded-full bg-brand-500 text-white items-center justify-center <?= $active ? 'flex' : 'hidden' ?>">
                                        <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="lot-category-wrap" class="grid grid-cols-1 sm:grid-cols-2 gap-4 <?= $showCategory ? '' : 'hidden' ?>">
                        <div>
                            <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.section')) ?></label>
                            <div class="relative" data-lot-select-wrap>
                                <select id="lot-category-parent" class="hidden" <?= $showCategory ? '' : 'disabled' ?>>
                                    <?php foreach ($categoryTree as $parent => $children): ?>
                                        <option value="<?= htmlspecialchars($parent) ?>" <?= $currentParent === $parent ? 'selected' : '' ?>><?= htmlspecialchars(ProductHelper::categoryLabel($parent)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" data-lot-trigger class="<?= $selectTrigger ?>" aria-haspopup="listbox" aria-expanded="false" <?= $showCategory ? '' : 'disabled' ?>>
                                    <span data-lot-label class="truncate"><?= htmlspecialchars(ProductHelper::categoryLabel($currentParent)) ?></span>
                                    <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div data-lot-menu class="hidden absolute z-30 mt-1.5 w-full max-h-64 overflow-y-auto bg-white dark:bg-ink-800 border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-1.5" role="listbox"></div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.subsection')) ?></label>
                            <div class="relative" data-lot-select-wrap>
                                <select name="category" id="lot-category" class="hidden" <?= $showCategory ? '' : 'disabled' ?>>
                                    <?php foreach ($categoryTree[$currentParent] ?? [] as $child): ?>
                                        <option value="<?= htmlspecialchars(ProductHelper::formatCategory($currentParent, $child)) ?>" <?= $currentChild === $child ? 'selected' : '' ?>><?= htmlspecialchars(ProductHelper::categoryLabel($child)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" data-lot-trigger class="<?= $selectTrigger ?>" aria-haspopup="listbox" aria-expanded="false" <?= $showCategory ? '' : 'disabled' ?>>
                                    <span data-lot-label class="truncate"><?= htmlspecialchars(ProductHelper::categoryLabel($currentChild)) ?></span>
                                    <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div data-lot-menu class="hidden absolute z-30 mt-1.5 w-full max-h-64 overflow-y-auto bg-white dark:bg-ink-800 border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-1.5" role="listbox"></div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.title_field')) ?></label>
                        <input type="text" name="title" required class="<?= $input ?>" value="<?= htmlspecialchars($editing['title'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.description')) ?></label>
                        <textarea name="description" rows="2" required class="ui-input w-full p-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
                    </div>
                    <div id="lot-exchange-wrap" class="<?= ($editing['type'] ?? '') === 'exchange' ? '' : 'hidden' ?>">
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.exchange_for')) ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="exchange_for" id="lot-exchange-for" maxlength="255" class="<?= $input ?>"
                               placeholder="<?= htmlspecialchars(t('profile.exchange_for_ph')) ?>"
                               value="<?= htmlspecialchars($editing['exchange_for'] ?? '') ?>"
                               <?= ($editing['type'] ?? '') === 'exchange' ? 'required' : '' ?>>
                        <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('profile.exchange_for_hint')) ?></p>
                    </div>
                    <div id="lot-free-note" class="<?= ($editing['type'] ?? '') === 'free' ? '' : 'hidden' ?> text-xs font-semibold text-violet-700 dark:text-violet-300 bg-violet-50 dark:bg-violet-900/20 border border-violet-100 dark:border-violet-800/40 rounded-xl px-3 py-2">
                        <?= htmlspecialchars(t('profile.free_price_note')) ?>
                    </div>
                    <div id="lot-service-note" class="<?= $currentType === 'service' && !$editing ? '' : 'hidden' ?> text-xs font-semibold text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40 rounded-xl px-3 py-2">
                        <?= htmlspecialchars(t('profile.service_board_note', ['amount' => \App\Models\Wallet::formatMoney(ProductHelper::SERVICE_LISTING_FEE)])) ?>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div id="lot-price-wrap" class="<?= in_array($currentType, $noPriceTypes, true) ? 'hidden' : '' ?>">
                            <label class="block text-xs font-bold mb-1" id="lot-price-label"><?= htmlspecialchars(t('profile.price_kzt')) ?></label>
                            <input type="text" name="price" id="lot-price" <?= in_array($currentType, $noPriceTypes, true) ? '' : 'required' ?> class="<?= $input ?>" value="<?= htmlspecialchars((string) ($editing['price'] ?? '')) ?>">
                        </div>
                        <div id="lot-location-wrap" class="<?= in_array($currentType, $noPriceTypes, true) ? 'col-span-2' : '' ?>">
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.location')) ?></label>
                            <input type="text" name="location" class="<?= $input ?>" value="<?= htmlspecialchars($editing['location'] ?? 'Караганда') ?>">
                        </div>
                    </div>
                    <?php
                    $editKind = $editing['auction_kind'] ?? 'english';
                    $editHours = 24;
                    if (!empty($editing['auction_start_at']) && !empty($editing['auction_end_at'])) {
                        $diffH = (int) round((strtotime((string) $editing['auction_end_at']) - strtotime((string) $editing['auction_start_at'])) / 3600);
                        if (in_array($diffH, [1, 6, 24, 72, 168], true)) {
                            $editHours = $diffH;
                        }
                    }
                    $editInactivity = (int) round(((int) ($editing['inactivity_timeout_seconds'] ?? 86400)) / 3600);
                    if (!in_array($editInactivity, [1, 6, 24, 72, 168], true)) {
                        $editInactivity = 24;
                    }
                    $editStepMinutes = max(1, (int) round(((int) ($editing['auction_step_interval'] ?? 60)) / 60));
                    ?>
                    <div id="lot-auction-wrap" class="<?= ($editing['type'] ?? '') === 'auction' ? 'space-y-4' : 'hidden space-y-4' ?>">
                        <div>
                            <label class="block text-xs font-bold mb-2"><?= htmlspecialchars(t('profile.auction_kind')) ?></label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <?php foreach (['english' => t('auctions.kind_english'), 'dutch' => t('auctions.kind_dutch'), 'continuous' => t('auctions.kind_continuous')] as $kindKey => $kindLabel): ?>
                                    <label class="flex items-start gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer bg-white dark:bg-white/[0.04]">
                                        <input type="radio" name="auction_kind" value="<?= $kindKey ?>" class="mt-0.5 auction-kind-radio"
                                               <?= $editKind === $kindKey ? 'checked' : '' ?>>
                                        <span>
                                            <span class="block text-xs font-semibold text-ink-800 dark:text-gray-100"><?= htmlspecialchars($kindLabel) ?></span>
                                            <span class="block text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars(t('profile.auction_kind_hint_' . $kindKey)) ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.bid_step')) ?></label>
                                <input type="text" name="bid_step" id="lot-bid-step" class="<?= $input ?>" value="<?= htmlspecialchars((string) ($editing['bid_step'] ?? '1000')) ?>">
                            </div>
                            <div id="lot-auction-hours-wrap">
                                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.auction_duration')) ?></label>
                                <select name="auction_hours" class="<?= $input ?>">
                                    <?php foreach ([1 => '1 ч', 6 => '6 ч', 24 => '24 ч', 72 => '3 дн', 168 => '7 дн'] as $h => $hl): ?>
                                        <option value="<?= $h ?>" <?= $editHours === $h ? 'selected' : '' ?>><?= htmlspecialchars($hl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="lot-inactivity-wrap" class="hidden">
                                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.auction_inactivity')) ?></label>
                                <select name="inactivity_hours" class="<?= $input ?>">
                                    <?php foreach ([1 => '1 ч', 6 => '6 ч', 24 => '24 ч', 72 => '3 дн', 168 => '7 дн'] as $h => $hl): ?>
                                        <option value="<?= $h ?>" <?= $editInactivity === $h ? 'selected' : '' ?>><?= htmlspecialchars($hl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div id="lot-dutch-wrap" class="grid grid-cols-2 gap-4 hidden">
                            <div>
                                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.auction_min_price')) ?></label>
                                <input type="text" name="auction_min_price" class="<?= $input ?>" value="<?= htmlspecialchars((string) ($editing['auction_min_price'] ?? '')) ?>">
                            </div>
                            <div>
                                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.auction_step_minutes')) ?></label>
                                <input type="number" name="auction_step_minutes" min="1" class="<?= $input ?>" value="<?= htmlspecialchars((string) $editStepMinutes) ?>">
                            </div>
                        </div>
                        <div id="lot-buy-now-wrap">
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.auction_buy_now')) ?></label>
                            <input type="text" name="auction_buy_now" class="<?= $input ?>" value="<?= htmlspecialchars((string) ($editing['auction_buy_now'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('profile.auction_buy_now_ph')) ?>">
                            <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('profile.auction_buy_now_hint')) ?></p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.auction_reserve')) ?></label>
                            <input type="text" name="auction_reserve" class="<?= $input ?>" value="<?= htmlspecialchars((string) ($editing['auction_reserve'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('profile.auction_reserve_ph')) ?>">
                            <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('profile.auction_reserve_hint')) ?></p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.whatsapp')) ?></label>
                        <input type="tel" name="whatsapp" inputmode="tel" maxlength="20" class="<?= $input ?>"
                               placeholder="<?= htmlspecialchars(t('profile.whatsapp_ph')) ?>"
                               value="<?= htmlspecialchars(ProductHelper::formatWhatsappInput($editing['whatsapp'] ?? '')) ?>">
                        <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('profile.whatsapp_hint')) ?></p>
                    </div>
                    <script>
                    (function () {
                        const typeSelect = document.getElementById('lot-type');
                        const priceWrap = document.getElementById('lot-price-wrap');
                        const priceInput = document.getElementById('lot-price');
                        const locationWrap = document.getElementById('lot-location-wrap');
                        const exchangeWrap = document.getElementById('lot-exchange-wrap');
                        const exchangeInput = document.getElementById('lot-exchange-for');
                        const freeNote = document.getElementById('lot-free-note');
                        const serviceNote = document.getElementById('lot-service-note');
                        const submitBtn = document.getElementById('lot-submit-btn');
                        const isEditingLot = <?= $editing ? 'true' : 'false' ?>;
                        const publishLabel = <?= json_encode(t('profile.publish')) ?>;
                        const publishServiceLabel = <?= json_encode(t('profile.publish_service', ['amount' => \App\Models\Wallet::formatMoney(ProductHelper::SERVICE_LISTING_FEE)])) ?>;
                        const categoryWrap = document.getElementById('lot-category-wrap');
                        const parentSelect = document.getElementById('lot-category-parent');
                        const categorySelect = document.getElementById('lot-category');
                        const auctionWrap = document.getElementById('lot-auction-wrap');
                        const priceLabel = document.getElementById('lot-price-label');
                        const hoursWrap = document.getElementById('lot-auction-hours-wrap');
                        const inactivityWrap = document.getElementById('lot-inactivity-wrap');
                        const dutchWrap = document.getElementById('lot-dutch-wrap');
                        const buyNowWrap = document.getElementById('lot-buy-now-wrap');
                        const priceLabelDefault = <?= json_encode(t('profile.price_kzt')) ?>;
                        const priceLabelAuction = <?= json_encode(t('profile.auction_start_price')) ?>;
                        const noPrice = ['free', 'exchange', 'service'];
                        const withCategory = <?= js_encode($productTypesWithCategory) ?>;
                        const tree = <?= js_encode($categoryTree) ?>;
                        const labels = <?= js_encode(array_combine(array_keys($categoryTree), array_map(
                            static fn ($parent) => ProductHelper::categoryLabel($parent),
                            array_keys($categoryTree)
                        )) + array_reduce($categoryTree, static function (array $labels, array $children): array {
                            foreach ($children as $child) $labels[$child] = ProductHelper::categoryLabel($child);
                            return $labels;
                        }, [])) ?>;
                        if (!typeSelect || !priceWrap || !priceInput) return;

                        const checkSvg = '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

                        function closeLotMenus(except) {
                            document.querySelectorAll('[data-lot-menu]').forEach(function (menu) {
                                if (except && menu === except) return;
                                menu.classList.add('hidden');
                            });
                            document.querySelectorAll('[data-lot-trigger]').forEach(function (btn) {
                                if (except && btn.nextElementSibling === except) return;
                                btn.setAttribute('aria-expanded', 'false');
                            });
                        }

                        function bindLotSelect(select) {
                            if (!select) return;
                            const wrap = select.closest('[data-lot-select-wrap]');
                            if (!wrap) return;
                            const btn = wrap.querySelector('[data-lot-trigger]');
                            const menu = wrap.querySelector('[data-lot-menu]');
                            const labelEl = wrap.querySelector('[data-lot-label]');
                            if (!btn || !menu || !labelEl) return;

                            function renderMenu() {
                                const selected = select.options[select.selectedIndex];
                                labelEl.textContent = selected ? selected.textContent : '';
                                btn.disabled = select.disabled;
                                btn.classList.toggle('opacity-50', select.disabled);
                                btn.classList.toggle('pointer-events-none', select.disabled);
                                btn.classList.toggle('cursor-pointer', !select.disabled);

                                menu.innerHTML = '';
                                Array.from(select.options).forEach(function (opt, i) {
                                    const isSel = opt.selected || i === select.selectedIndex;
                                    const item = document.createElement('button');
                                    item.type = 'button';
                                    item.setAttribute('role', 'option');
                                    item.setAttribute('aria-selected', isSel ? 'true' : 'false');
                                    item.className = 'w-full flex items-center gap-2 px-3.5 py-2.5 text-sm text-left transition ' +
                                        (isSel
                                            ? 'bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 font-semibold'
                                            : 'text-ink-800 dark:text-gray-200 hover:bg-black/[0.04] dark:hover:bg-white/5');
                                    const text = document.createElement('span');
                                    text.className = 'truncate';
                                    text.textContent = opt.textContent;
                                    item.appendChild(text);
                                    if (isSel) {
                                        const mark = document.createElement('span');
                                        mark.className = 'ml-auto shrink-0 text-brand-500';
                                        mark.innerHTML = checkSvg;
                                        item.appendChild(mark);
                                    }
                                    item.addEventListener('click', function () {
                                        select.value = opt.value;
                                        select.dispatchEvent(new Event('change'));
                                        closeLotMenus();
                                        renderMenu();
                                    });
                                    menu.appendChild(item);
                                });
                            }

                            btn.addEventListener('click', function (e) {
                                e.preventDefault();
                                if (select.disabled) return;
                                const willOpen = menu.classList.contains('hidden');
                                closeLotMenus(willOpen ? menu : null);
                                menu.classList.toggle('hidden', !willOpen);
                                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                            });

                            select.addEventListener('change', renderMenu);
                            select.refreshLotUI = renderMenu;
                            renderMenu();
                        }

                        bindLotSelect(parentSelect);
                        bindLotSelect(categorySelect);

                        document.addEventListener('click', function (e) {
                            if (!e.target.closest('[data-lot-select-wrap]')) closeLotMenus();
                        });
                        document.addEventListener('keydown', function (e) {
                            if (e.key === 'Escape') closeLotMenus();
                        });

                        function syncTypeCards() {
                            const value = typeSelect.value;
                            document.querySelectorAll('.lot-type-card').forEach(function (card) {
                                const active = card.getAttribute('data-type') === value;
                                const idle = card.getAttribute('data-idle') || '';
                                const on = card.getAttribute('data-on') || '';
                                const ring = card.getAttribute('data-ring') || '';
                                card.setAttribute('aria-selected', active ? 'true' : 'false');
                                card.className = 'lot-type-card group relative flex flex-col items-center gap-2 px-2.5 py-3 rounded-2xl border text-center transition ' +
                                    (active
                                        ? ring + ' shadow-soft'
                                        : 'border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.04] hover:border-brand-300/70 hover:shadow-sm');
                                const icon = card.querySelector('.lot-type-icon');
                                if (icon) icon.className = 'lot-type-icon w-10 h-10 rounded-xl flex items-center justify-center transition ' + (active ? on : idle);
                                const check = card.querySelector('.lot-type-check');
                                if (check) {
                                    check.classList.toggle('hidden', !active);
                                    check.classList.toggle('flex', active);
                                }
                            });
                        }

                        document.querySelectorAll('.lot-type-card').forEach(function (card) {
                            card.addEventListener('click', function () {
                                const value = card.getAttribute('data-type');
                                if (!value || typeSelect.value === value) return;
                                typeSelect.value = value;
                                typeSelect.dispatchEvent(new Event('change'));
                            });
                        });

                        function syncAuctionKind() {
                            const checked = document.querySelector('.auction-kind-radio:checked');
                            const kind = checked ? checked.value : 'english';
                            if (hoursWrap) hoursWrap.classList.toggle('hidden', kind === 'continuous');
                            if (inactivityWrap) inactivityWrap.classList.toggle('hidden', kind !== 'continuous');
                            if (dutchWrap) dutchWrap.classList.toggle('hidden', kind !== 'dutch');
                            if (buyNowWrap) buyNowWrap.classList.toggle('hidden', kind === 'dutch');
                        }

                        function syncPriceField() {
                            const type = typeSelect.value;
                            const hide = noPrice.indexOf(type) !== -1;
                            priceWrap.classList.toggle('hidden', hide);
                            priceInput.required = !hide;
                            if (hide) priceInput.value = '';
                            if (locationWrap) locationWrap.classList.toggle('col-span-2', hide);
                            if (priceLabel) priceLabel.textContent = type === 'auction' ? priceLabelAuction : priceLabelDefault;
                            if (auctionWrap) {
                                auctionWrap.classList.toggle('hidden', type !== 'auction');
                                if (type === 'auction') syncAuctionKind();
                            }

                            const isExchange = type === 'exchange';
                            if (exchangeWrap) exchangeWrap.classList.toggle('hidden', !isExchange);
                            if (exchangeInput) {
                                exchangeInput.required = isExchange;
                                if (!isExchange) exchangeInput.value = '';
                            }
                            if (freeNote) freeNote.classList.toggle('hidden', type !== 'free');
                            if (serviceNote) serviceNote.classList.toggle('hidden', type !== 'service' || isEditingLot);
                            if (submitBtn && !isEditingLot) {
                                submitBtn.textContent = type === 'service' ? publishServiceLabel : publishLabel;
                            }
                        }

                        function fillSubcategories(keepValue) {
                            if (!parentSelect || !categorySelect || !tree) return;
                            const parent = parentSelect.value;
                            const children = tree[parent] || [];
                            const prev = keepValue || categorySelect.value;
                            categorySelect.innerHTML = '';
                            children.forEach(function (child) {
                                const value = parent + ' / ' + child;
                                const opt = document.createElement('option');
                                opt.value = value;
                                opt.textContent = labels[child] || child;
                                if (value === prev || child === prev || prev.indexOf(child) !== -1) {
                                    opt.selected = true;
                                }
                                categorySelect.appendChild(opt);
                            });
                            if (!categorySelect.value && categorySelect.options.length) {
                                categorySelect.selectedIndex = 0;
                            }
                            if (typeof categorySelect.refreshLotUI === 'function') categorySelect.refreshLotUI();
                        }

                        function syncCategoryField() {
                            if (!categoryWrap || !categorySelect || !parentSelect) return;
                            const show = withCategory.indexOf(typeSelect.value) !== -1;
                            categoryWrap.classList.toggle('hidden', !show);
                            categorySelect.disabled = !show;
                            parentSelect.disabled = !show;
                            if (typeof parentSelect.refreshLotUI === 'function') parentSelect.refreshLotUI();
                            if (typeof categorySelect.refreshLotUI === 'function') categorySelect.refreshLotUI();
                            if (!show) closeLotMenus();
                        }

                        if (parentSelect) {
                            parentSelect.addEventListener('change', function () {
                                fillSubcategories('');
                            });
                        }

                        typeSelect.addEventListener('change', function () {
                            syncTypeCards();
                            syncPriceField();
                            syncCategoryField();
                        });
                        document.querySelectorAll('.auction-kind-radio').forEach(function (radio) {
                            radio.addEventListener('change', syncAuctionKind);
                        });
                        syncTypeCards();
                        syncPriceField();
                        syncCategoryField();
                    })();
                    </script>
                    <div>
                        <label class="block text-xs font-bold mb-1">
                            <?= htmlspecialchars(t('profile.photos')) ?> <span class="text-red-500">*</span>
                            <span class="font-medium text-gray-400 normal-case">· до 3 шт.</span>
                        </label>
                        <p class="text-[11px] text-gray-400 mb-2">Кликните по фото, чтобы сделать его обложкой</p>
                        <?php
                        $existingFiles = $editing ? ProductHelper::decodeImages($editing) : [];
                        $existingCover = $editing['image'] ?? ($existingFiles[0] ?? '');
                        $existingPayload = [];
                        foreach ($existingFiles as $file) {
                            $existingPayload[] = [
                                'name' => $file,
                                'url' => ProductHelper::url('public/uploads/products/' . basename($file)),
                                'cover' => $file === $existingCover,
                            ];
                        }
                        ?>
                        <div id="lot-photos" class="space-y-3"
                             data-existing='<?= htmlspecialchars(json_encode($existingPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'>
                            <div id="lot-photo-grid" class="grid grid-cols-3 gap-2"></div>
                            <input type="hidden" name="cover" id="lot-cover" value="">
                            <div id="lot-keep-inputs"></div>
                            <label id="lot-add-btn" class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-ink-800 dark:text-gray-200">
                                <span class="px-3 py-2 rounded-xl bg-accent-500 text-white">+ Добавить фото</span>
                                <input type="file" id="lot-images-input" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="hidden">
                            </label>
                            <p class="text-[11px] text-gray-400">JPG, PNG, WEBP, GIF · до 5 МБ каждое</p>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="submit" id="lot-submit-btn" class="flex-1 bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft">
                            <?= htmlspecialchars($editing ? t('profile.update') : t('profile.publish')) ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="<?= ProductHelper::url('/profile?tab=lots') ?>" class="sm:w-auto text-center px-5 py-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 text-xs font-bold uppercase tracking-wider hover:bg-white/60 dark:hover:bg-white/5 transition">
                                <?= htmlspecialchars(t('profile.cancel_edit')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                <script>
                (function () {
                    const root = document.getElementById('lot-photos');
                    if (!root) return;
                    const grid = document.getElementById('lot-photo-grid');
                    const coverInput = document.getElementById('lot-cover');
                    const keepBox = document.getElementById('lot-keep-inputs');
                    const fileInput = document.getElementById('lot-images-input');
                    const addBtn = document.getElementById('lot-add-btn');
                    const MAX = 3;
                    let items = [];

                    try {
                        const existing = JSON.parse(root.dataset.existing || '[]');
                        items = existing.map(function (img) {
                            return { kind: 'existing', name: img.name, url: img.url, cover: !!img.cover };
                        });
                    } catch (e) {}

                    if (items.length && !items.some(function (i) { return i.cover; })) {
                        items[0].cover = true;
                    }

                    function syncFileInput() {
                        const dt = new DataTransfer();
                        items.filter(function (i) { return i.kind === 'new' && i.file; }).forEach(function (i) {
                            dt.items.add(i.file);
                        });
                        fileInput.files = dt.files;
                    }

                    function syncHidden() {
                        keepBox.innerHTML = '';
                        items.forEach(function (item) {
                            if (item.kind !== 'existing') return;
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'keep_images[]';
                            input.value = item.name;
                            keepBox.appendChild(input);
                        });

                        const coverItem = items.find(function (i) { return i.cover; }) || items[0];
                        if (!coverItem) {
                            coverInput.value = '';
                            return;
                        }
                        if (coverItem.kind === 'existing') {
                            coverInput.value = coverItem.name;
                        } else {
                            const newIndex = items.filter(function (i) { return i.kind === 'new'; }).indexOf(coverItem);
                            coverInput.value = '__new__' + Math.max(0, newIndex);
                        }
                    }

                    function render() {
                        grid.innerHTML = '';
                        items.forEach(function (item, idx) {
                            const card = document.createElement('button');
                            card.type = 'button';
                            card.className = 'relative aspect-square rounded-xl overflow-hidden border-2 bg-black/[0.03] dark:bg-white/5 transition ' +
                                (item.cover ? 'border-brand-500 shadow-soft' : 'border-black/[0.08] dark:border-white/10 hover:border-brand-300');
                            card.title = 'Сделать обложкой';
                            card.innerHTML =
                                '<img src="' + item.url + '" alt="" class="w-full h-full object-cover">' +
                                (item.cover ? '<span class="absolute top-1.5 left-1.5 text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-md bg-accent-500 text-white">Обложка</span>' : '') +
                                '<span data-remove class="absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-ink-900/70 text-white text-sm leading-6 hover:bg-red-600">×</span>';
                            card.addEventListener('click', function (e) {
                                if (e.target.closest('[data-remove]')) {
                                    e.preventDefault();
                                    items.splice(idx, 1);
                                    if (items.length && !items.some(function (i) { return i.cover; })) {
                                        items[0].cover = true;
                                    }
                                    syncFileInput();
                                    render();
                                    return;
                                }
                                items.forEach(function (i) { i.cover = false; });
                                item.cover = true;
                                render();
                            });
                            grid.appendChild(card);
                        });
                        addBtn.classList.toggle('hidden', items.length >= MAX);
                        syncHidden();
                    }

                    fileInput.addEventListener('change', function () {
                        const files = Array.from(fileInput.files || []);
                        const room = MAX - items.length;
                        let added = 0;
                        files.forEach(function (file) {
                            if (added >= room) return;
                            if (!file.type || file.type.indexOf('image/') !== 0) return;
                            const dup = items.some(function (i) {
                                return i.kind === 'new' && i.file && i.file.name === file.name && i.file.size === file.size;
                            });
                            if (dup) return;
                            const url = URL.createObjectURL(file);
                            items.push({ kind: 'new', file: file, url: url, cover: items.length === 0 });
                            added++;
                        });
                        if (items.length && !items.some(function (i) { return i.cover; })) {
                            items[0].cover = true;
                        }
                        syncFileInput();
                        render();
                    });

                    const form = root.closest('form');
                    form?.addEventListener('submit', function (e) {
                        syncFileInput();
                        syncHidden();
                        if (!items.length) {
                            e.preventDefault();
                            alert('Добавьте хотя бы одно фото');
                        }
                    });

                    render();
                })();
                </script>

                <h3 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-3">Опубликованные (<?= count($products) ?>)</h3>
                <?php if (empty($products)): ?>
                    <p class="text-sm text-gray-400"><?= htmlspecialchars(t('profile.no_lots')) ?></p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($products as $p):
                            $thumb = ProductHelper::imageUrl($p);
                        ?>
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-white dark:bg-white/5 border border-black/[0.06] dark:border-white/10 rounded-2xl px-4 py-3.5 <?= !empty($editing) && (int) $editing['id'] === (int) $p['id'] ? 'border-brand-400/60 shadow-soft' : '' ?>">
                                <a href="<?= ProductHelper::url('/product/' . $p['id']) ?>" class="flex items-center gap-3 flex-1 min-w-0 hover:opacity-80 transition">
                                    <div class="w-12 h-12 rounded-xl overflow-hidden bg-brand-50 dark:bg-white/5 flex-shrink-0 flex items-center justify-center">
                                        <?php if ($thumb): ?>
                                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?= ProductHelper::icon($p['type'], 'w-6 h-6 text-brand-500/80') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold truncate"><?= htmlspecialchars($p['title']) ?></div>
                                        <div class="text-[10px] text-gray-400 mt-0.5"><?= ProductHelper::label($p['type']) ?><?= in_array($p['type'], ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true) && !empty($p['category']) ? ' · ' . htmlspecialchars($p['category']) : '' ?> · <?= htmlspecialchars($p['status']) ?></div>
                                    </div>
                                </a>
                                <div class="flex items-center justify-between sm:justify-end gap-3 flex-shrink-0">
                                    <span class="text-sm font-display font-bold text-brand-600 whitespace-nowrap"><?= htmlspecialchars(ProductHelper::formatPrice($p)) ?></span>
                                    <div class="flex items-center gap-1.5">
                                        <?php \App\Core\View::partial('partials/share-buttons', ['item' => $p, 'compact' => true]); ?>
                                        <a href="<?= ProductHelper::url('/profile?tab=lots&edit=' . $p['id']) ?>" class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold border border-black/[0.08] dark:border-white/10 hover:border-brand-400/50 hover:bg-brand-50/60 dark:hover:bg-white/5 transition" title="Редактировать">
                                            Изменить
                                        </a>
                                        <form method="post" action="<?= ProductHelper::url('/profile/lots/' . $p['id'] . '/delete') ?>" onsubmit="return confirm('Удалить объявление «<?= htmlspecialchars($p['title'], ENT_QUOTES) ?>»?');">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold text-red-600 border border-red-200/80 dark:border-red-500/30 hover:bg-red-50 dark:hover:bg-red-500/10 transition" title="Удалить">
                                                Удалить
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
