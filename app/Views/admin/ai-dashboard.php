<?php use App\Helpers\ProductHelper; ?>
<?php /** @var array $metrics */ ?>
<?php
$e = static fn ($s): string => htmlspecialchars((string) $s);
$periodHours = (int) ($metrics['period_hours'] ?? 24);
$es = $evalSummary ?? [];
$candidates = $candidates ?? [];
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title">AI Dashboard</h1>
            <p class="adm-sub">Метрики за последние <?= $periodHours ?> ч · <?= $e($metrics['generated_at'] ?? '') ?></p>
        </div>
        <div class="adm-head-actions">
            <nav class="adm-seg" aria-label="period">
                <a href="<?= ProductHelper::url('/admin/ai?hours=24') ?>" class="<?= $periodHours === 24 ? 'is-active' : '' ?>">24ч</a>
                <a href="<?= ProductHelper::url('/admin/ai?hours=168') ?>" class="<?= $periodHours === 168 ? 'is-active' : '' ?>">7д</a>
            </nav>
            <a href="<?= ProductHelper::url('/admin/ai-chats') ?>" class="adm-btn">Чаты</a>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="adm-stats" style="--cols:4">
        <?php
        $cards = [
            ['Requests', (int) ($metrics['requests'] ?? 0)],
            ['Errors', (int) ($metrics['errors'] ?? 0)],
            ['Latency ms', $metrics['avg_latency_ms'] ?? '—'],
            ['Tool calls', (int) ($metrics['tool_calls'] ?? 0)],
            ['Active chats', (int) ($metrics['active_conversations'] ?? 0)],
            ['Escalated', (int) ($metrics['escalated'] ?? 0)],
            ['Feedback', (int) ($metrics['feedback']['count'] ?? 0)],
            ['Learning queue', (int) ($metrics['learning_pending'] ?? 0)],
        ];
        foreach ($cards as [$label, $val]):
        ?>
            <div class="adm-stat">
                <div class="adm-stat-label"><?= $e($label) ?></div>
                <div class="adm-stat-value"><?= $e($val) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="adm-grid-2">
        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title">Intents</h2>
            </div>
            <?php if (empty($metrics['intents'])): ?>
                <div class="adm-empty">Пока нет данных.</div>
            <?php else: ?>
                <div class="adm-list">
                    <?php foreach ($metrics['intents'] as $row): ?>
                        <div class="adm-row">
                            <div class="adm-row-main truncate"><?= $e($row['name']) ?></div>
                            <div class="adm-row-side num" style="color:var(--adm-text)"><?= (int) $row['count'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="adm-card-foot">
                <div>
                    Feedback avg: <?= $e($metrics['feedback']['avg'] ?? '—') ?>
                    · Positive: <?= (int) ($metrics['feedback']['positive'] ?? 0) ?>
                    · Negative: <?= (int) ($metrics['feedback']['negative'] ?? 0) ?>
                </div>
                <div style="margin-top:2px">
                    Tools denied: <?= (int) ($metrics['tool_denied'] ?? 0) ?>
                    · tool errors: <?= (int) ($metrics['tool_errors'] ?? 0) ?>
                </div>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title">Models</h2>
            </div>
            <?php if (empty($metrics['models'])): ?>
                <div class="adm-empty">Нет записей в ai_model_configs. Запустите <span class="mono">php bin/ai_migrate.php</span></div>
            <?php else: ?>
                <div class="adm-table-wrap">
                    <table class="adm-table">
                        <tbody>
                            <?php foreach ($metrics['models'] as $m): ?>
                                <tr>
                                    <td><span class="strong"><?= $e($m['task_type']) ?></span> <span class="muted">· <?= $e($m['provider']) ?></span></td>
                                    <td class="num mono"><?= $e($m['model']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="adm-grid-2">
        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title">Active prompt</h2>
                <span class="mono">
                    <?= $e($metrics['prompt']['slug'] ?? '—') ?>
                    @
                    <span style="font-weight:600;color:var(--adm-text)"><?= $e($metrics['prompt']['version'] ?? '—') ?></span>
                </span>
            </div>
            <div class="adm-card-body adm-stack" style="gap:12px">
                <form method="post" action="<?= ProductHelper::url('/admin/ai/prompt-rollback') ?>" class="adm-toolbar">
                    <?= csrf_field() ?>
                    <input name="version" placeholder="v1" class="adm-input" style="width:120px;min-width:0" required>
                    <button class="adm-btn">Rollback</button>
                </form>
                <div class="adm-toolbar">
                    <form method="post" action="<?= ProductHelper::url('/admin/ai/reindex') ?>" class="adm-inline">
                        <?= csrf_field() ?>
                        <button class="adm-btn">Reindex knowledge (embeddings)</button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/admin/ai/learning-process') ?>" class="adm-inline">
                        <?= csrf_field() ?>
                        <button class="adm-btn">Process learning queue</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title">Evaluations (7д)</h2>
                <span class="muted" style="font-size:12px">
                    Positive: <?= (int) ($es['positive_count'] ?? 0) ?>
                    · Negative: <?= (int) ($es['negative_count'] ?? 0) ?>
                    · CSAT avg: <?= $e($es['avg_csat'] ?? '—') ?>
                </span>
            </div>
            <?php if (!empty($es['top_reasons'])): ?>
                <div class="adm-list">
                    <?php foreach ($es['top_reasons'] as $r): ?>
                        <div class="adm-row">
                            <div class="adm-row-main truncate"><?= $e($r['reason']) ?></div>
                            <div class="adm-row-side num" style="color:var(--adm-text)"><?= (int) $r['count'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="adm-empty">Пока нет негативных причин.</div>
            <?php endif; ?>
        </section>
    </div>

    <section class="adm-card">
        <div class="adm-card-head">
            <div>
                <h2 class="adm-card-title">Prompt candidates</h2>
                <p class="adm-card-sub">Кандидаты не активируются автоматически — только после approve.</p>
            </div>
        </div>
        <?php if (empty($candidates)): ?>
            <div class="adm-empty">Нет кандидатов.</div>
        <?php else: ?>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Preview</th>
                            <th class="num">Score</th>
                            <th class="num"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $c): ?>
                            <tr>
                                <td class="mono strong nowrap"><?= $e($c['version']) ?></td>
                                <td style="min-width:260px"><div class="adm-clamp-2 muted" style="font-size:12px;max-width:80ch"><?= $e($c['preview'] ?? '') ?></div></td>
                                <td class="num"><?= $e($c['evaluation_score'] ?? '—') ?></td>
                                <td>
                                    <div class="actions">
                                        <form method="post" action="<?= ProductHelper::url('/admin/ai/prompt-approve') ?>" class="adm-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="version" value="<?= $e($c['version']) ?>">
                                            <button class="adm-btn adm-btn--sm">Approve → active</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
