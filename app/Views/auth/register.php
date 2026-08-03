<?php
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;

$offerSections = [
    ['title' => t('offer.s1_title'), 'body' => t('offer.s1_body')],
    ['title' => t('offer.s2_title'), 'body' => t('offer.s2_body')],
    ['title' => t('offer.s3_title'), 'body' => t('offer.s3_body')],
    ['title' => t('offer.s4_title'), 'body' => t('offer.s4_body')],
    ['title' => t('offer.s5_title'), 'body' => t('offer.s5_body')],
    ['title' => t('offer.s6_title'), 'body' => t('offer.s6_body')],
    ['title' => t('offer.s7_title'), 'body' => t('offer.s7_body')],
    ['title' => t('offer.s8_title'), 'body' => t('offer.s8_body')],
    ['title' => t('offer.s9_title'), 'body' => t('offer.s9_body')],
    ['title' => t('offer.s10_title'), 'body' => t('offer.s10_body')],
    ['title' => t('offer.s11_title'), 'body' => t('offer.s11_body')],
    ['title' => t('offer.s12_title'), 'body' => t('offer.s12_body')],
];
?>
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

        <button type="button" id="google-continue-btn" disabled
           class="w-full inline-flex items-center justify-center gap-3 h-12 rounded-2xl border border-black/10 bg-white text-sm font-semibold text-ink-900 transition disabled:opacity-45 disabled:cursor-not-allowed enabled:hover:bg-gray-50">
            <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.5-.4-3.5z"/>
                <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16.1 19 13 24 13c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.3 26.7 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.3 4.1-4.1 5.5l.1.1 6.2 5.2C39.2 36.3 44 31.5 44 24c0-1.3-.1-2.5-.4-3.5z"/>
            </svg>
            <?= htmlspecialchars(t('auth.google_continue')) ?>
        </button>
        <p class="text-[11px] text-gray-400 text-center mt-2 leading-relaxed">
            <?= htmlspecialchars(t('auth.offer_google_hint')) ?>
            <button type="button" class="js-open-offer text-brand-600 font-semibold hover:underline"><?= htmlspecialchars(t('auth.offer_link')) ?></button>.
        </p>

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-black/10"></div></div>
            <div class="relative flex justify-center"><span class="bg-white px-3 text-[11px] uppercase tracking-wider text-gray-400 font-semibold"><?= htmlspecialchars(t('auth.or_email')) ?></span></div>
        </div>

        <form method="post" action="<?= ProductHelper::url('/register') ?>" class="space-y-4" id="register-form">
            <?= csrf_field() ?>
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
                <div class="relative">
                    <input type="password" name="password" id="register-password" required minlength="8" class="w-full h-11 px-4 pr-11 rounded-xl border border-black/10 bg-white text-sm focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <button type="button" onclick="togglePass('register-password')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-ink-800" aria-label="Toggle password"><?= IconHelper::svg('eye', 'w-4 h-4') ?></button>
                </div>
            </div>

            <div class="rounded-2xl border border-black/10 bg-gray-50/80 px-3.5 py-3 space-y-2.5 text-center">
                <p class="text-[12px] text-gray-500 leading-relaxed" id="offer-read-hint">
                    <?= htmlspecialchars(t('auth.offer_read_hint')) ?>
                    <button type="button" class="js-open-offer text-brand-600 font-semibold hover:underline"><?= htmlspecialchars(t('auth.offer_link')) ?></button>.
                </p>
                <label id="offer-accept-row" class="hidden items-start gap-2.5 cursor-pointer select-none text-left">
                    <input type="checkbox" name="accept_offer" id="accept-offer" value="1" disabled required
                           class="mt-0.5 h-4 w-4 shrink-0 rounded border-black/20 text-brand-600 focus:ring-brand-500/30">
                    <span class="text-[12px] text-ink-800 leading-relaxed font-medium">
                        <?= htmlspecialchars(t('auth.offer_accept_full')) ?>
                    </span>
                </label>
            </div>

            <button type="submit" id="register-submit" disabled class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition disabled:opacity-45 disabled:cursor-not-allowed enabled:hover:bg-accent-400"><?= htmlspecialchars(t('auth.register_btn')) ?></button>
        </form>
        <script>
        function togglePass(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.type = el.type === 'password' ? 'text' : 'password';
        }
        </script>

        <p class="text-center text-xs text-gray-400 mt-6">
            <?= htmlspecialchars(t('auth.have_account')) ?> <a href="<?= ProductHelper::url('/login') ?>" class="text-brand-600 font-semibold"><?= htmlspecialchars(t('auth.login_btn')) ?></a>
        </p>
    </div>
</div>

<div id="offer-modal" class="fixed inset-0 z-[100] hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-ink-900/50 backdrop-blur-sm" data-offer-close></div>
    <div class="relative z-10 flex min-h-full items-end sm:items-center justify-center p-0 sm:p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="offer-modal-title"
             class="w-full sm:max-w-2xl max-h-[92vh] sm:max-h-[88vh] flex flex-col rounded-t-[28px] sm:rounded-[28px] bg-white shadow-2xl border border-white/70 overflow-hidden">
            <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-black/5 shrink-0">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('offer.eyebrow')) ?></p>
                    <h2 id="offer-modal-title" class="font-display text-lg font-bold text-ink-900 mt-0.5"><?= htmlspecialchars(t('offer.title')) ?></h2>
                </div>
                <button type="button" data-offer-close class="h-9 w-9 rounded-xl border border-black/10 text-gray-500 hover:text-ink-900 hover:bg-gray-50 transition shrink-0" aria-label="Close">&times;</button>
            </div>

            <div id="offer-scroll" class="flex-1 overflow-y-auto px-5 py-4 space-y-4 overscroll-contain">
                <p class="text-sm text-gray-500 leading-relaxed"><?= htmlspecialchars(t('offer.lead')) ?></p>
                <p class="text-xs text-gray-400"><?= htmlspecialchars(t('offer.effective')) ?></p>
                <?php foreach ($offerSections as $section): ?>
                <div class="rounded-2xl border border-black/[0.06] bg-gray-50/70 p-4 space-y-2">
                    <h3 class="font-display text-sm font-bold text-ink-900"><?= htmlspecialchars($section['title']) ?></h3>
                    <div class="text-[13px] text-ink-700/80 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($section['body']) ?></div>
                </div>
                <?php endforeach; ?>
                <div class="rounded-2xl border border-black/[0.06] bg-gray-50/70 p-4 space-y-1.5">
                    <h3 class="font-display text-sm font-bold text-ink-900"><?= htmlspecialchars(t('offer.contacts_title')) ?></h3>
                    <p class="text-[13px] text-ink-700/80 leading-relaxed"><?= htmlspecialchars(t('offer.contacts_body')) ?></p>
                    <p class="text-[13px]"><a href="mailto:support@zakopeyki.kz" class="text-brand-600 font-semibold">support@zakopeyki.kz</a></p>
                </div>
                <div id="offer-scroll-end" class="h-1" aria-hidden="true"></div>
            </div>

            <div class="shrink-0 border-t border-black/5 px-5 py-4 bg-white space-y-2">
                <p id="offer-scroll-hint" class="text-[11px] text-gray-400 text-center"><?= htmlspecialchars(t('auth.offer_scroll_hint')) ?></p>
                <button type="button" id="offer-accept-btn" disabled
                        class="w-full h-12 rounded-2xl bg-accent-500 text-white font-display font-bold text-xs uppercase tracking-wider transition disabled:opacity-40 disabled:cursor-not-allowed enabled:hover:bg-accent-400">
                    <?= htmlspecialchars(t('auth.offer_accept_btn')) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('offer-modal');
    var scrollEl = document.getElementById('offer-scroll');
    var acceptBtn = document.getElementById('offer-accept-btn');
    var scrollHint = document.getElementById('offer-scroll-hint');
    var checkbox = document.getElementById('accept-offer');
    var acceptRow = document.getElementById('offer-accept-row');
    var readHint = document.getElementById('offer-read-hint');
    var submitBtn = document.getElementById('register-submit');
    var googleBtn = document.getElementById('google-continue-btn');
    var googleUrl = <?= js_encode(ProductHelper::url('/auth/google')) ?>;
    var scrolledToEnd = false;
    var offerAccepted = false;

    function openOffer(e) {
        if (e) e.preventDefault();
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        scrolledToEnd = false;
        acceptBtn.disabled = true;
        scrollHint.classList.remove('hidden');
        scrollEl.scrollTop = 0;
        requestAnimationFrame(checkScroll);
    }

    function closeOffer() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function checkScroll() {
        if (!scrollEl) return;
        var nearBottom = scrollEl.scrollTop + scrollEl.clientHeight >= scrollEl.scrollHeight - 24;
        // If content fits without scroll, unlock immediately
        var noScrollNeeded = scrollEl.scrollHeight <= scrollEl.clientHeight + 8;
        if (nearBottom || noScrollNeeded) {
            scrolledToEnd = true;
            acceptBtn.disabled = false;
            scrollHint.classList.add('hidden');
        }
    }

    function unlockAcceptance() {
        offerAccepted = true;
        acceptRow.classList.remove('hidden');
        acceptRow.classList.add('flex');
        readHint.classList.add('hidden');
        checkbox.disabled = false;
        checkbox.checked = true;
        checkbox.setAttribute('required', 'required');
        submitBtn.disabled = false;
        googleBtn.disabled = false;
        closeOffer();
    }

    function syncSubmit() {
        var ok = offerAccepted && checkbox.checked;
        submitBtn.disabled = !ok;
        googleBtn.disabled = !ok;
    }

    document.querySelectorAll('.js-open-offer').forEach(function (el) {
        el.addEventListener('click', openOffer);
    });
    document.querySelectorAll('[data-offer-close]').forEach(function (el) {
        el.addEventListener('click', closeOffer);
    });
    scrollEl.addEventListener('scroll', checkScroll, { passive: true });
    acceptBtn.addEventListener('click', function () {
        if (!scrolledToEnd) return;
        unlockAcceptance();
    });
    checkbox.addEventListener('change', syncSubmit);
    googleBtn.addEventListener('click', function () {
        if (googleBtn.disabled || !offerAccepted || !checkbox.checked) return;
        window.location.href = googleUrl;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeOffer();
    });
})();
</script>
