<?php
use App\Helpers\ProductHelper;
use App\Models\Bonus;

$result = $result ?? ['valid' => false];
$valid = !empty($result['valid']);
$code = (string) ($result['code'] ?? '');
$name = (string) ($result['name'] ?? '');
$error = (string) ($result['error'] ?? '');
?>
<section class="max-w-lg mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('bonuses.verify_eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('bonuses.verify_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('bonuses.verify_subtitle')) ?></p>
    </div>

    <div class="rounded-[24px] border p-6 shadow-soft <?= $valid
        ? 'bg-emerald-50/90 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-800/50'
        : 'bg-red-50/90 dark:bg-red-950/30 border-red-200 dark:border-red-900/40' ?>">
        <p class="font-display text-xl font-bold <?= $valid ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' ?>">
            <?= htmlspecialchars($valid ? t('bonuses.verify_ok') : t('bonuses.verify_fail')) ?>
        </p>

        <?php if ($code !== ''): ?>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                <?= htmlspecialchars(t('bonuses.verify_code')) ?>:
                <span class="font-display font-bold tracking-widest text-ink-900 dark:text-white"><?= htmlspecialchars($code) ?></span>
            </p>
        <?php endif; ?>

        <?php if ($valid && $name !== ''): ?>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                <?= htmlspecialchars(t('bonuses.verify_user')) ?>:
                <span class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars($name) ?></span>
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                <?= htmlspecialchars(t('bonuses.verify_balance')) ?>:
                <span class="font-semibold tabular-nums"><?= htmlspecialchars(Bonus::format((int) ($result['balance'] ?? 0))) ?></span>
            </p>
        <?php elseif ($error === 'threshold'): ?>
            <p class="mt-3 text-sm text-red-700/90 dark:text-red-300"><?= htmlspecialchars(t('bonuses.verify_threshold')) ?></p>
        <?php elseif ($error === 'not_found' || $error === 'bad_code'): ?>
            <p class="mt-3 text-sm text-red-700/90 dark:text-red-300"><?= htmlspecialchars(t('bonuses.verify_unknown')) ?></p>
        <?php endif; ?>
    </div>

    <a href="<?= ProductHelper::url('/bonuses') ?>" class="inline-flex font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-2xl bg-ink-900 hover:bg-ink-800 text-white transition">
        <?= htmlspecialchars(t('bonuses.verify_back')) ?>
    </a>
</section>
