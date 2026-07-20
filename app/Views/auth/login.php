<?php use App\Helpers\ProductHelper; ?>
<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <a href="<?= ProductHelper::url('/') ?>" class="inline-flex items-baseline gap-0.5">
            <span class="font-display text-4xl font-extrabold text-brand-500">za</span>
            <span class="font-display text-3xl font-bold text-ink-900">kopeyki<span class="text-brand-500">.kz</span></span>
        </a>
        <p class="text-sm text-gray-500 mt-3"><?= htmlspecialchars(t('auth.login_heading')) ?></p>
    </div>

    <div class="bg-white/90 backdrop-blur-xl rounded-[28px] shadow-2xl border border-white/70 p-8">
        <?php if (!empty($error)): ?>
            <div class="mb-4 bg-red-50 text-red-600 text-sm font-semibold px-4 py-3 rounded-2xl"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= ProductHelper::url('/login') ?>" class="space-y-4">
            <div>
                <label class="block text-[13px] font-semibold mb-1.5">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required class="w-full h-11 px-4 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
            </div>
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('auth.password')) ?></label>
                <input type="password" name="password" required class="w-full h-11 px-4 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
            </div>
            <button class="w-full bg-ink-900 hover:bg-black text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('auth.login_btn')) ?></button>
        </form>

        <p class="text-center text-xs text-gray-400 mt-6">
            <?= htmlspecialchars(t('auth.no_account')) ?> <a href="<?= ProductHelper::url('/register') ?>" class="text-brand-600 font-semibold"><?= htmlspecialchars(t('auth.register_link')) ?></a>
        </p>

    </div>
</div>
