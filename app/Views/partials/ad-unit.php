<?php

use App\Helpers\ProductHelper;

$ads = array_values(array_filter($ads ?? [], 'is_array'));
$variant = (string) ($variant ?? 'banner');
if ($ads === []) {
    return;
}
$slider = $variant === 'hero';
?>
<div class="<?= $variant === 'side' ? '' : 'w-full' ?>" <?= $slider ? 'data-ad-slider' : '' ?>>
    <?php if ($slider && count($ads) > 1): ?>
        <div class="flex items-center justify-between gap-3 mb-2">
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('ads.sponsored')) ?></p>
            <div class="flex gap-1.5">
                <button type="button" data-ad-prev class="h-8 w-8 rounded-full border border-black/10 dark:border-white/15 text-sm" aria-label="<?= htmlspecialchars(t('ads.prev')) ?>">‹</button>
                <button type="button" data-ad-next class="h-8 w-8 rounded-full border border-black/10 dark:border-white/15 text-sm" aria-label="<?= htmlspecialchars(t('ads.next')) ?>">›</button>
            </div>
        </div>
    <?php endif; ?>
    <div class="<?= $slider ? 'relative overflow-hidden rounded-[28px]' : '' ?>">
        <?php foreach ($ads as $i => $ad): ?>
            <a href="<?= htmlspecialchars((string) $ad['href']) ?>"
               target="_blank"
               rel="sponsored noopener noreferrer"
               data-ad-token="<?= htmlspecialchars((string) $ad['token']) ?>"
               data-ad-slide="<?= (int) $i ?>"
               class="<?= $slider && $i > 0 ? 'hidden ' : '' ?><?= $variant === 'exclusive'
                   ? 'relative block overflow-hidden rounded-[28px] min-h-[180px] sm:min-h-[220px] '
                   : ($variant === 'side'
                       ? 'block overflow-hidden rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] '
                       : 'relative block overflow-hidden rounded-[28px] border border-black/[0.06] dark:border-white/10 bg-ink-900 ') ?>">
                <img src="<?= htmlspecialchars((string) $ad['image']) ?>" alt="<?= htmlspecialchars((string) $ad['title']) ?>" class="<?= $variant === 'side' ? 'w-full aspect-[4/5] object-cover' : 'w-full h-44 sm:h-64 object-cover' ?>" loading="lazy" decoding="async">
                <span class="absolute top-3 left-3 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-lg bg-black/55 text-white"><?= htmlspecialchars(t('ads.sponsored')) ?></span>
                <?php if ($variant === 'exclusive'): ?>
                    <span class="absolute bottom-3 left-3 right-3 text-white font-display font-bold text-lg drop-shadow"><?= htmlspecialchars((string) $ad['title']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php if (empty($GLOBALS['zk_ad_script'])): $GLOBALS['zk_ad_script'] = true; ?>
<script>
window.__adImpressionUrl = <?= js_encode(ProductHelper::url('/ads/impression')) ?>;
(function () {
    if (window.__zkAdsBound) return;
    window.__zkAdsBound = true;
    function count(el) {
        if (!el || el.getAttribute('data-ad-counted') === '1' || el.getAttribute('data-ad-sending') === '1') return;
        var token = el.getAttribute('data-ad-token');
        if (!token) return;
        el.setAttribute('data-ad-sending', '1');
        var body = new URLSearchParams();
        body.set('token', token);
        body.set('_csrf', window.__csrfToken || '');
        fetch(window.__adImpressionUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': window.__csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            if (res.ok) el.setAttribute('data-ad-counted', '1');
            else el.removeAttribute('data-ad-sending');
        }).catch(function () {
            el.removeAttribute('data-ad-sending');
        });
    }
    var io = null;
    if ('IntersectionObserver' in window) {
        io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.5) count(entry.target);
            });
        }, { threshold: [0.5] });
    }
    function watch(el) {
        if (!el) return;
        if (io) io.observe(el);
        else count(el);
    }
    document.querySelectorAll('[data-ad-token]').forEach(function (el) {
        if (el.closest('[data-ad-slider]')) return;
        watch(el);
    });
    document.querySelectorAll('[data-ad-slider]').forEach(function (root) {
        var slides = Array.prototype.slice.call(root.querySelectorAll('[data-ad-slide]'));
        if (!slides.length) return;
        var index = 0;
        function show(next) {
            index = (next + slides.length) % slides.length;
            slides.forEach(function (slide, i) {
                slide.classList.toggle('hidden', i !== index);
            });
            watch(slides[index]);
        }
        show(0);
        var prev = root.querySelector('[data-ad-prev]');
        var nextBtn = root.querySelector('[data-ad-next]');
        if (prev) prev.addEventListener('click', function () { show(index - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { show(index + 1); });
        if (slides.length > 1) {
            setInterval(function () { show(index + 1); }, 7000);
        }
    });
})();
</script>
<?php endif; ?>
