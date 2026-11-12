<?php
/**
 * Виртуальная примерка: модальное окно (на мобильном — bottom sheet). docs/tryon/06-testing.md §6.
 * @var array $item
 */
use App\Helpers\ProductHelper;

$tryonI18n = [
    'status_queued' => t('tryon.ui.status_queued'),
    'status_processing' => t('tryon.ui.status_processing'),
    'left_today' => t('tryon.ui.left_today'),
    'photo_small' => t('tryon.err.image_too_small'),
    'photo_format' => t('tryon.err.unsupported_format'),
    'photo_big' => t('tryon.err.file_too_large'),
    'network' => t('tryon.ui.network'),
    'generic' => t('tryon.err.generic'),
    'delete_confirm' => t('tryon.ui.delete_confirm'),
    'garment' => t('tryon.ui.garment_n'),
    'job_failed' => t('tryon.ui.job_failed'),
    'job_failed_product' => t('tryon.err.product_not_supported'),
    'expires' => t('tryon.ui.expires'),
];
$tryonCfg = [
    'api' => ProductHelper::url('/api/v1/try-on'),
    'productId' => (int) $item['id'],
    'i18n' => $tryonI18n,
];
$tabBtn = 'flex-1 inline-flex items-center justify-center gap-2 h-12 px-3 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 hover:border-brand-400 hover:text-brand-600 font-semibold text-sm transition cursor-pointer';
$primaryBtn = 'w-full inline-flex items-center justify-center gap-2 h-12 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-semibold text-sm transition disabled:opacity-40 disabled:pointer-events-none';
$ghostBtn = 'inline-flex items-center justify-center gap-2 h-11 px-3 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 hover:border-brand-400 hover:text-brand-600 font-semibold text-[13px] transition';
?>
<div id="tryon-modal"
     class="hidden fixed inset-0 z-[96] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4"
     role="dialog" aria-modal="true" aria-labelledby="tryon-title" aria-hidden="true"
     data-tryon-config="<?= htmlspecialchars(js_encode($tryonCfg)) ?>">
    <div class="w-full sm:max-w-lg max-h-[92dvh] flex flex-col bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10"
         data-tryon-panel>
        <div class="sm:hidden flex justify-center pt-3 pb-1" aria-hidden="true">
            <span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span>
        </div>
        <div class="px-5 pt-3 sm:pt-6 pb-3 flex items-start justify-between gap-3 border-b border-black/[0.06] dark:border-white/10">
            <div class="min-w-0">
                <h3 id="tryon-title" class="font-display text-xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('tryon.ui.title')) ?></h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 truncate"><?= htmlspecialchars((string) $item['title']) ?></p>
            </div>
            <button type="button" data-tryon-close class="w-9 h-9 rounded-full flex items-center justify-center text-gray-400 hover:text-ink-800 dark:hover:text-white hover:bg-black/[0.05] dark:hover:bg-white/10 transition shrink-0" aria-label="<?= htmlspecialchars(t('tryon.ui.close')) ?>">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-4">
            <!-- Загрузка возможностей -->
            <div data-tryon-step="loading" class="py-10 flex justify-center">
                <span class="w-8 h-8 rounded-full border-2 border-brand-200 border-t-brand-600 animate-spin" aria-hidden="true"></span>
                <span class="sr-only"><?= htmlspecialchars(t('tryon.ui.loading')) ?></span>
            </div>

            <!-- Шаг 1: фото и согласие -->
            <div data-tryon-step="setup" class="hidden space-y-4">
                <div data-tryon-garments-wrap>
                    <p class="text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-2"><?= htmlspecialchars(t('tryon.ui.choose_garment')) ?></p>
                    <div class="flex gap-2 overflow-x-auto pb-1" data-tryon-garments role="radiogroup" aria-label="<?= htmlspecialchars(t('tryon.ui.choose_garment')) ?>"></div>
                </div>

                <div>
                    <p class="text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-2"><?= htmlspecialchars(t('tryon.ui.your_photo')) ?></p>
                    <div data-tryon-preview-wrap class="hidden relative rounded-2xl overflow-hidden bg-ink-50 dark:bg-white/5 mb-3">
                        <img data-tryon-preview alt="<?= htmlspecialchars(t('tryon.ui.your_photo')) ?>" class="w-full max-h-[40dvh] object-contain">
                        <button type="button" data-tryon-photo-reset class="absolute top-2 right-2 h-8 px-3 rounded-full bg-white/95 dark:bg-ink-900/85 text-xs font-semibold text-ink-800 dark:text-white shadow-sm"><?= htmlspecialchars(t('tryon.ui.change_photo')) ?></button>
                    </div>
                    <div data-tryon-pickers class="grid grid-cols-2 gap-2.5">
                        <label class="<?= $tabBtn ?>">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <span class="truncate"><?= htmlspecialchars(t('tryon.ui.pick_gallery')) ?></span>
                            <input type="file" accept="image/jpeg,image/png,image/webp,image/*" class="sr-only" data-tryon-file>
                        </label>
                        <label class="<?= $tabBtn ?>">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span class="truncate"><?= htmlspecialchars(t('tryon.ui.pick_camera')) ?></span>
                            <input type="file" accept="image/*" capture="user" class="sr-only" data-tryon-file>
                        </label>
                    </div>
                    <ul class="mt-3 space-y-1 text-xs text-gray-500 dark:text-gray-400 list-disc pl-4">
                        <li><?= htmlspecialchars(t('tryon.ui.tip_pose')) ?></li>
                        <li><?= htmlspecialchars(t('tryon.ui.tip_light')) ?></li>
                        <li><?= htmlspecialchars(t('tryon.ui.tip_alone')) ?></li>
                    </ul>
                </div>

                <label class="flex items-start gap-2.5 p-3 rounded-2xl bg-ink-50 dark:bg-white/5 cursor-pointer">
                    <input type="checkbox" data-tryon-consent class="mt-0.5 w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 shrink-0">
                    <span class="text-xs leading-relaxed text-ink-700 dark:text-gray-300">
                        <?= htmlspecialchars(t('tryon.consent_text')) ?>
                        <a href="<?= ProductHelper::url('/privacy') ?>" target="_blank" rel="noopener" class="text-brand-600 dark:text-brand-400 underline"><?= htmlspecialchars(t('tryon.ui.privacy_link')) ?></a>
                    </span>
                </label>

                <p data-tryon-error class="hidden text-sm font-semibold text-rose-600 dark:text-rose-400" role="alert"></p>
            </div>

            <!-- Шаг 2: ожидание -->
            <div data-tryon-step="progress" class="hidden py-6 text-center space-y-4">
                <div class="relative mx-auto w-16 h-16">
                    <span class="absolute inset-0 rounded-full border-4 border-brand-100 dark:border-brand-500/20"></span>
                    <span class="absolute inset-0 rounded-full border-4 border-transparent border-t-brand-600 animate-spin"></span>
                </div>
                <p data-tryon-status class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('tryon.ui.status_queued')) ?></p>
                <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs mx-auto"><?= htmlspecialchars(t('tryon.ui.can_close')) ?></p>
            </div>

            <!-- Шаг 3: результат -->
            <div data-tryon-step="result" class="hidden space-y-3">
                <p data-tryon-test-badge class="hidden text-xs font-bold text-white bg-rose-600 rounded-lg px-3 py-2 text-center"><?= htmlspecialchars(t('tryon.ui.test_badge')) ?></p>
                <div class="relative rounded-2xl overflow-hidden bg-ink-50 dark:bg-white/5 select-none" data-tryon-compare style="--pos:50%">
                    <img data-tryon-result alt="<?= htmlspecialchars(t('tryon.ui.result_alt')) ?>" class="block w-full max-h-[55dvh] object-contain">
                    <img data-tryon-before alt="<?= htmlspecialchars(t('tryon.ui.before_alt')) ?>" class="absolute inset-0 w-full h-full object-contain bg-ink-50 dark:bg-ink-800" style="clip-path: inset(0 calc(100% - var(--pos)) 0 0)">
                    <span class="absolute inset-y-0 w-0.5 bg-white shadow pointer-events-none" style="left: var(--pos)" aria-hidden="true"></span>
                    <span class="absolute top-2 left-2 text-[11px] font-semibold bg-ink-900/70 text-white rounded-full px-2 py-0.5"><?= htmlspecialchars(t('tryon.ui.before')) ?></span>
                    <span class="absolute top-2 right-2 text-[11px] font-semibold bg-brand-600/90 text-white rounded-full px-2 py-0.5"><?= htmlspecialchars(t('tryon.ui.after')) ?></span>
                    <input type="range" min="0" max="100" value="50" data-tryon-slider class="absolute inset-x-0 bottom-2 mx-auto w-2/3 accent-brand-600" aria-label="<?= htmlspecialchars(t('tryon.ui.compare')) ?>">
                </div>
                <p data-tryon-expires class="text-xs text-gray-500 dark:text-gray-400 text-center"></p>
            </div>

            <!-- Ошибка задания -->
            <div data-tryon-step="failed" class="hidden py-6 text-center space-y-2">
                <p class="font-display font-bold text-ink-900 dark:text-white" data-tryon-failed-text></p>
            </div>
        </div>

        <div class="px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))] border-t border-black/[0.06] dark:border-white/10 space-y-2.5">
            <div data-tryon-actions="setup" class="hidden space-y-2">
                <button type="button" data-tryon-submit class="<?= $primaryBtn ?>" disabled><?= htmlspecialchars(t('tryon.ui.start')) ?></button>
                <p data-tryon-limits class="text-xs text-center text-gray-500 dark:text-gray-400"></p>
            </div>
            <div data-tryon-actions="progress" class="hidden">
                <button type="button" data-tryon-delete class="<?= $ghostBtn ?> w-full"><?= htmlspecialchars(t('tryon.ui.cancel')) ?></button>
            </div>
            <div data-tryon-actions="result" class="hidden grid grid-cols-2 gap-2.5">
                <button type="button" data-tryon-again class="<?= $ghostBtn ?>"><?= htmlspecialchars(t('tryon.ui.again')) ?></button>
                <button type="button" data-tryon-delete class="<?= $ghostBtn ?>"><?= htmlspecialchars(t('tryon.ui.delete')) ?></button>
                <button type="button" data-tryon-close class="<?= $primaryBtn ?> col-span-2"><?= htmlspecialchars(t('tryon.ui.back_to_buy')) ?></button>
            </div>
            <div data-tryon-actions="failed" class="hidden grid grid-cols-2 gap-2.5">
                <button type="button" data-tryon-again class="<?= $ghostBtn ?>"><?= htmlspecialchars(t('tryon.ui.again')) ?></button>
                <button type="button" data-tryon-close class="<?= $ghostBtn ?>"><?= htmlspecialchars(t('tryon.ui.close')) ?></button>
            </div>
        </div>
    </div>
</div>
