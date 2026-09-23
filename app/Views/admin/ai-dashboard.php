<?php use App\Helpers\ProductHelper; ?>
<?php /** @var array $metrics */ ?>
<section class="space-y-6 fade-up">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-violet-500">AI Platform</p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1">AI Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Метрики за последние <?= (int) ($metrics['period_hours'] ?? 24) ?> ч · <?= htmlspecialchars((string) ($metrics['generated_at'] ?? '')) ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= ProductHelper::url('/admin/ai?hours=24') ?>" class="text-xs px-3 py-1.5 rounded-full border border-black/10 dark:border-white/10">24ч</a>
            <a href="<?= ProductHelper::url('/admin/ai?hours=168') ?>" class="text-xs px-3 py-1.5 rounded-full border border-black/10 dark:border-white/10">7д</a>
            <a href="<?= ProductHelper::url('/admin/ai-chats') ?>" class="text-xs px-3 py-1.5 rounded-full bg-violet-600 text-white">Чаты</a>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <?php
        $cards = [
            ['Requests', (int) ($metrics['requests'] ?? 0), 'violet'],
            ['Errors', (int) ($metrics['errors'] ?? 0), 'red'],
            ['Latency ms', $metrics['avg_latency_ms'] ?? '—', 'sky'],
            ['Tool calls', (int) ($metrics['tool_calls'] ?? 0), 'teal'],
            ['Active chats', (int) ($metrics['active_conversations'] ?? 0), 'brand'],
            ['Escalated', (int) ($metrics['escalated'] ?? 0), 'amber'],
            ['Feedback', (int) ($metrics['feedback']['count'] ?? 0), 'emerald'],
            ['Learning queue', (int) ($metrics['learning_pending'] ?? 0), 'indigo'],
        ];
        foreach ($cards as [$label, $val, $color]):
        ?>
        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] px-4 py-3 shadow-soft">
            <p class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold"><?= htmlspecialchars($label) ?></p>
            <p class="font-display text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars((string) $val) ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white mb-3">Intents</h2>
            <?php if (empty($metrics['intents'])): ?>
                <p class="text-sm text-gray-400">Пока нет данных.</p>
            <?php else: ?>
                <ul class="space-y-1.5 text-sm">
                    <?php foreach ($metrics['intents'] as $row): ?>
                        <li class="flex justify-between gap-2">
                            <span class="text-ink-800 dark:text-gray-200 truncate"><?= htmlspecialchars($row['name']) ?></span>
                            <span class="font-semibold text-violet-600"><?= (int) $row['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="text-xs text-gray-400 mt-3">
                Feedback avg: <?= htmlspecialchars((string) ($metrics['feedback']['avg'] ?? '—')) ?>
                · 👍 <?= (int) ($metrics['feedback']['positive'] ?? 0) ?>
                · 👎 <?= (int) ($metrics['feedback']['negative'] ?? 0) ?>
            </p>
            <p class="text-xs text-gray-400 mt-1">
                Tools denied: <?= (int) ($metrics['tool_denied'] ?? 0) ?>
                · tool errors: <?= (int) ($metrics['tool_errors'] ?? 0) ?>
            </p>
        </div>

        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 shadow-soft space-y-4">
            <div>
                <h2 class="font-display font-bold text-ink-900 dark:text-white mb-2">Models</h2>
                <?php if (empty($metrics['models'])): ?>
                    <p class="text-sm text-gray-400">Нет записей в ai_model_configs. Запустите php bin/ai_migrate.php</p>
                <?php else: ?>
                    <ul class="space-y-1 text-sm">
                        <?php foreach ($metrics['models'] as $m): ?>
                            <li class="flex justify-between gap-2">
                                <span><?= htmlspecialchars((string) $m['task_type']) ?> · <?= htmlspecialchars((string) $m['provider']) ?></span>
                                <span class="font-mono text-xs"><?= htmlspecialchars((string) $m['model']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="font-display font-bold text-ink-900 dark:text-white mb-2">Active prompt</h2>
                <p class="text-sm">
                    <?= htmlspecialchars((string) ($metrics['prompt']['slug'] ?? '—')) ?>
                    @
                    <span class="font-semibold"><?= htmlspecialchars((string) ($metrics['prompt']['version'] ?? '—')) ?></span>
                </p>
                <form method="post" action="<?= ProductHelper::url('/admin/ai/prompt-rollback') ?>" class="mt-2 flex gap-2 items-center">
                    <?= csrf_field() ?>
                    <input name="version" placeholder="v1" class="ui-input rounded-xl px-3 py-2 text-sm w-28" required>
                    <button class="rounded-xl bg-ink-900 text-white text-xs font-semibold px-3 py-2 cursor-pointer">Rollback</button>
                </form>
            </div>

            <form method="post" action="<?= ProductHelper::url('/admin/ai/reindex') ?>">
                <?= csrf_field() ?>
                <button class="rounded-xl border border-violet-300 text-violet-700 dark:text-violet-300 text-xs font-semibold px-3 py-2 cursor-pointer hover:bg-violet-50 dark:hover:bg-violet-950/30">
                    Reindex knowledge (embeddings)
                </button>
            </form>

            <form method="post" action="<?= ProductHelper::url('/admin/ai/learning-process') ?>">
                <?= csrf_field() ?>
                <button class="rounded-xl border border-indigo-300 text-indigo-700 dark:text-indigo-300 text-xs font-semibold px-3 py-2 cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-950/30">
                    Process learning queue
                </button>
            </form>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white mb-2">Evaluations (7д)</h2>
            <?php $es = $evalSummary ?? []; ?>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                👍 <?= (int) ($es['positive_count'] ?? 0) ?>
                · 👎 <?= (int) ($es['negative_count'] ?? 0) ?>
                · CSAT avg: <?= htmlspecialchars((string) ($es['avg_csat'] ?? '—')) ?>
            </p>
            <?php if (!empty($es['top_reasons'])): ?>
                <ul class="mt-2 space-y-1 text-sm">
                    <?php foreach ($es['top_reasons'] as $r): ?>
                        <li class="flex justify-between gap-2">
                            <span class="truncate"><?= htmlspecialchars((string) $r['reason']) ?></span>
                            <span class="font-semibold"><?= (int) $r['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-sm text-gray-400 mt-2">Пока нет негативных причин.</p>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white mb-2">Prompt candidates</h2>
            <p class="text-xs text-gray-400 mb-2">Кандидаты не активируются автоматически — только после approve.</p>
            <?php if (empty($candidates)): ?>
                <p class="text-sm text-gray-400">Нет кандидатов.</p>
            <?php else: ?>
                <ul class="space-y-3 text-sm">
                    <?php foreach ($candidates as $c): ?>
                        <li class="border border-black/5 dark:border-white/10 rounded-xl p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono font-semibold"><?= htmlspecialchars((string) $c['version']) ?></span>
                                <span class="text-xs text-gray-400">score: <?= htmlspecialchars((string) ($c['evaluation_score'] ?? '—')) ?></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars((string) ($c['preview'] ?? '')) ?></p>
                            <form method="post" action="<?= ProductHelper::url('/admin/ai/prompt-approve') ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="version" value="<?= htmlspecialchars((string) $c['version']) ?>">
                                <button class="rounded-lg bg-emerald-600 text-white text-xs font-semibold px-3 py-1.5 cursor-pointer">Approve → active</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>
