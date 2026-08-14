<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

$kindTone = [
    'english' => 'bg-red-500 text-white',
    'dutch' => 'bg-amber-500 text-white',
    'continuous' => 'bg-violet-600 text-white',
];
?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500 mb-1"><?= htmlspecialchars(t('auctions.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white flex items-center gap-2.5">
            <span class="inline-flex text-accent-500">
                <svg class="w-6 h-6 sm:w-7 sm:h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 13-7.5 7.5c-.83.83-2.17.83-3 0a2.12 2.12 0 0 1 0-3L11 10"/><path d="m16 16 6-6"/><path d="m8 8 6-6"/><path d="m9 7 8 8"/><path d="m21 11-8-8"/></svg>
            </span>
            <span><?= htmlspecialchars(t('auctions.title')) ?></span>
        </h2>
        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('auctions.subtitle')) ?></p>
        <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-gray-500">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-300"><?= htmlspecialchars(t('auctions.kind_english')) ?></span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300"><?= htmlspecialchars(t('auctions.kind_dutch')) ?></span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-violet-50 dark:bg-violet-500/10 text-violet-700 dark:text-violet-300"><?= htmlspecialchars(t('auctions.kind_continuous')) ?></span>
        </div>
    </div>
    <?php if (empty($items)): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/15 px-5 py-14 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('auctions.empty')) ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5">
            <?php foreach ($items as $item):
                $kind = $item['auction_kind'] ?? 'english';
                $showUrl = ProductHelper::url('/product/' . $item['id']);
                $imageUrls = ProductHelper::imageUrls($item);
                $imageUrl = $imageUrls[0] ?? ProductHelper::imageUrl($item);
                $price = (int) ($item['calculated_current_price'] ?? ($item['current_bid'] ?: $item['price']));
                $endAt = $item['auction_end_at'] ?? null;
                $buyNow = (int) ($item['buy_now_price'] ?? 0);
                $isOwn = Auth::check() && (int) ($item['user_id'] ?? 0) === (int) Auth::id();
                $galleryJson = htmlspecialchars(json_encode(array_values($imageUrls ?: array_filter([$imageUrl])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            ?>
                <article class="bg-white dark:bg-white/[0.04] rounded-2xl border border-black/[0.06] dark:border-white/10 overflow-hidden hover:shadow-soft transition duration-300 flex flex-col h-full group cursor-pointer"
                         data-auction-card
                         data-card-href="<?= htmlspecialchars($showUrl) ?>"
                         data-auction-id="<?= (int) $item['id'] ?>"
                         data-kind="<?= htmlspecialchars($kind) ?>"
                         data-end-at="<?= htmlspecialchars((string) $endAt) ?>"
                         data-last-bid="<?= htmlspecialchars((string) ($item['last_bid_at'] ?? '')) ?>"
                         data-inactivity="<?= (int) ($item['inactivity_timeout_seconds'] ?? 0) ?>">
                    <?php if ($imageUrl): ?>
                    <button type="button"
                            class="photo-wm aspect-[4/3] bg-ink-100 dark:bg-white/10 relative block overflow-hidden w-full cursor-zoom-in"
                            data-lightbox
                            data-lightbox-src="<?= htmlspecialchars($imageUrl) ?>"
                            data-lightbox-gallery="<?= $galleryJson ?>"
                            data-lightbox-index="0"
                            aria-label="<?= htmlspecialchars(t('product.zoom')) ?>">
                        <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="absolute inset-0 w-full h-full object-cover transition duration-300 group-hover:scale-105 pointer-events-none">
                        <span class="absolute top-2 left-2 pointer-events-none text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-lg <?= $kindTone[$kind] ?? 'bg-red-500 text-white' ?>">
                            <?= htmlspecialchars($item['auction_kind_label'] ?? t('auctions.kind_english')) ?>
                        </span>
                    </button>
                    <?php else: ?>
                    <a href="<?= $showUrl ?>" class="photo-wm aspect-[4/3] bg-ink-100 dark:bg-white/10 relative block overflow-hidden">
                        <span class="absolute top-2 left-2 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-lg <?= $kindTone[$kind] ?? 'bg-red-500 text-white' ?>">
                            <?= htmlspecialchars($item['auction_kind_label'] ?? t('auctions.kind_english')) ?>
                        </span>
                    </a>
                    <?php endif; ?>
                    <div class="p-3.5 flex flex-col gap-2 flex-1">
                        <a href="<?= $showUrl ?>" class="font-display font-bold text-sm text-ink-900 dark:text-white line-clamp-2 hover:text-accent-500"><?= htmlspecialchars($item['title']) ?></a>
                        <div class="mt-auto space-y-2">
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars($kind === 'dutch' ? t('auctions.buyout_price') : t('auctions.current_price')) ?></p>
                                <p class="font-display font-bold text-lg text-ink-900 dark:text-white" data-auction-price><?= number_format($price, 0, '', ' ') ?> ₸</p>
                                <?php if ($buyNow > 0 && $kind !== 'dutch'): ?>
                                    <p class="text-[11px] font-semibold text-accent-600 dark:text-accent-400 mt-0.5"><?= htmlspecialchars(t('auctions.buy_now')) ?>: <?= number_format($buyNow, 0, '', ' ') ?> ₸</p>
                                <?php endif; ?>
                            </div>
                            <div class="rounded-xl bg-ink-50 dark:bg-white/5 px-3 py-2 text-center">
                                <p class="text-[10px] uppercase tracking-wider text-gray-400" data-auction-timer-label><?= htmlspecialchars($kind === 'continuous' ? t('auctions.timer_open') : t('auctions.time_left')) ?></p>
                                <p class="font-mono text-sm font-bold text-ink-800 dark:text-white" data-auction-timer><?= $kind === 'continuous' ? '∞' : '—' ?></p>
                            </div>
                            <?php if ($isOwn): ?>
                                <p class="text-[11px] text-center text-gray-500 px-1"><?= htmlspecialchars(t('auctions.own_no_bid')) ?></p>
                                <a href="<?= $showUrl ?>" class="inline-flex items-center justify-center w-full font-display font-bold text-[11px] py-2.5 rounded-xl border border-black/10 dark:border-white/15 text-ink-800 dark:text-gray-200 uppercase tracking-wider">
                                    <?= htmlspecialchars(t('auctions.your_lot')) ?>
                                </a>
                            <?php else: ?>
                            <?php if ($kind === 'dutch' || $buyNow > 0): ?>
                                <?php if (Auth::check()): ?>
                                    <form method="post" action="<?= ProductHelper::url('/auctions/' . $item['id'] . '/buy-now') ?>">
                                        <?= csrf_field() ?>
                                        <button class="inline-flex items-center justify-center w-full font-display font-bold text-[11px] py-2.5 rounded-xl bg-accent-500 hover:bg-accent-400 text-white uppercase tracking-wider">
                                            <?= htmlspecialchars(t('auctions.buy_now')) ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="<?= ProductHelper::url('/login') ?>" class="inline-flex items-center justify-center w-full font-display font-bold text-[11px] py-2.5 rounded-xl bg-accent-500 hover:bg-accent-400 text-white uppercase tracking-wider">
                                        <?= htmlspecialchars(t('auctions.buy_now')) ?>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($kind !== 'dutch'): ?>
                            <a href="<?= $showUrl ?>" class="inline-flex items-center justify-center w-full font-display font-bold text-[11px] py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white uppercase tracking-wider">
                                <?= htmlspecialchars(t('card.bid')) ?>
                            </a>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <script>
        (function () {
            function pad(n) { return String(n).padStart(2, '0'); }
            function fmt(ms) {
                if (ms <= 0) return '00:00:00';
                var s = Math.floor(ms / 1000);
                var h = Math.floor(s / 3600);
                var m = Math.floor((s % 3600) / 60);
                return pad(h) + ':' + pad(m) + ':' + pad(s % 60);
            }
            function tickCard(card) {
                var kind = card.getAttribute('data-kind');
                var timer = card.querySelector('[data-auction-timer]');
                var label = card.querySelector('[data-auction-timer-label]');
                if (!timer) return;
                if (kind === 'continuous') {
                    timer.textContent = '∞';
                    return;
                }
                var end = card.getAttribute('data-end-at');
                if (!end) {
                    timer.textContent = '—';
                    return;
                }
                var leftEnd = new Date(end.replace(' ', 'T')).getTime() - Date.now();
                timer.textContent = fmt(leftEnd);
                if (leftEnd <= 30000 && label) label.textContent = <?= json_encode(t('auctions.sniping')) ?>;
            }
            function tickAll() {
                document.querySelectorAll('[data-auction-card]').forEach(tickCard);
            }
            tickAll();
            setInterval(tickAll, 1000);
        })();
        </script>
    <?php endif; ?>
</section>
