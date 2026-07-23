<?php use App\Helpers\ProductHelper; ?>
<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <a href="<?= ProductHelper::url('/') ?>" class="inline-flex items-baseline gap-0.5">
            <span class="font-display text-4xl font-extrabold text-brand-500">za</span>
            <span class="font-display text-3xl font-bold text-ink-900">kopeyki<span class="text-brand-500">.kz</span></span>
        </a>
        <p class="text-sm text-gray-500 mt-3"><?= htmlspecialchars(t('auth.register_heading')) ?></p>
    </div>

    <div class="bg-white/90 backdrop-blur-xl rounded-[28px] shadow-2xl border border-white/70 p-8">
        <?php if (!empty($error)): ?>
            <div class="mb-4 bg-red-50 text-red-600 text-sm font-semibold px-4 py-3 rounded-2xl"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <a href="<?= ProductHelper::url('/auth/google') ?>"
           class="w-full inline-flex items-center justify-center gap-3 h-12 rounded-2xl border border-black/10 bg-white hover:bg-gray-50 text-sm font-semibold text-ink-900 transition">
            <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.5-.4-3.5z"/>
                <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16.1 19 13 24 13c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.3 26.7 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.3 4.1-4.1 5.5l.1.1 6.2 5.2C39.2 36.3 44 31.5 44 24c0-1.3-.1-2.5-.4-3.5z"/>
            </svg>
            <?= htmlspecialchars(t('auth.google_continue')) ?>
        </a>

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-black/10"></div></div>
            <div class="relative flex justify-center"><span class="bg-white px-3 text-[11px] uppercase tracking-wider text-gray-400 font-semibold"><?= htmlspecialchars(t('auth.or_email')) ?></span></div>
        </div>

        <form method="post" action="<?= ProductHelper::url('/register') ?>" class="space-y-4">
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('auth.name')) ?></label>
                <input type="text" name="name" value="<?= htmlspecialchars($name ?? '') ?>" required class="w-full h-11 px-4 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
            </div>
            <div>
                <label class="block text-[13px] font-semibold mb-1.5">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required class="w-full h-11 px-4 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
            </div>
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('auth.phone_wa')) ?></label>
                <input type="text" name="phone" value="<?= htmlspecialchars($phone ?? '') ?>" placeholder="77001112233" class="w-full h-11 px-4 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
            </div>
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('auth.password')) ?></label>
                <input type="password" name="password" required minlength="6" class="w-full h-11 px-4 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
            </div>
            <button class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('auth.register_btn')) ?></button>
        </form>

        <p class="text-center text-xs text-gray-400 mt-6">
            <?= htmlspecialchars(t('auth.have_account')) ?> <a href="<?= ProductHelper::url('/login') ?>" class="text-brand-600 font-semibold"><?= htmlspecialchars(t('auth.login_btn')) ?></a>
        </p>
    </div>
</div>
