<?php
use App\Helpers\ProductHelper;
$e = static fn ($s): string => htmlspecialchars((string) $s);
$saved = static fn (bool $has): string => $has ? ' <span class="adm-badge adm-badge--ok"><span class="adm-dot"></span>' . htmlspecialchars(t('admin.stream_saved_secret')) . '</span>' : '';
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.stream_title')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.stream_hint')) ?></p>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e((string) $error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= ProductHelper::url('/admin/stream') ?>" class="adm-card" style="max-width:820px">
        <?= csrf_field() ?>
        <div class="adm-card-head">
            <h2 class="adm-card-title">Cloudflare Stream</h2>
        </div>
        <div class="adm-card-body adm-form-grid">
            <label class="adm-field">
                <span class="adm-label">Account ID</span>
                <input name="cf_stream_account_id" class="adm-input mono" value="<?= $e((string) ($accountId ?? '')) ?>">
            </label>
            <label class="adm-field">
                <span class="adm-label">API Token<?= $saved(!empty($hasToken)) ?></span>
                <input type="password" name="cf_stream_api_token" class="adm-input" autocomplete="new-password" placeholder="<?= !empty($hasToken) ? '••••••••' : '' ?>">
            </label>
            <label class="adm-field">
                <span class="adm-label">Customer subdomain</span>
                <input name="cf_stream_customer_subdomain" class="adm-input mono" placeholder="customer-xxxxx.cloudflarestream.com" value="<?= $e((string) ($customerSubdomain ?? '')) ?>">
            </label>
            <label class="adm-field">
                <span class="adm-label">Signing Key ID</span>
                <input name="cf_stream_signing_key_id" class="adm-input mono" value="<?= $e((string) ($signingKeyId ?? '')) ?>">
            </label>
            <label class="adm-field span-2">
                <span class="adm-label">Signing Key PEM<?= $saved(!empty($hasPem)) ?></span>
                <textarea name="cf_stream_signing_key_pem" rows="6" class="adm-textarea mono" style="font-size:12px" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
            </label>
            <label class="adm-field">
                <span class="adm-label">Webhook secret<?= $saved(!empty($hasWebhook)) ?></span>
                <input type="password" name="cf_stream_webhook_secret" class="adm-input" autocomplete="new-password">
            </label>
            <div class="adm-field">
                <span class="adm-label"><?= $e(t('admin.stream_webhook_url')) ?></span>
                <span class="mono muted" style="font-size:12px;overflow-wrap:anywhere;padding-top:8px"><?= $e(ProductHelper::url('/webhooks/cloudflare/stream')) ?></span>
            </div>
            <div class="span-2">
                <label class="adm-check">
                    <input type="checkbox" name="cf_stream_require_signed" value="1" <?= !empty($requireSigned) ? 'checked' : '' ?>>
                    <?= $e(t('admin.stream_require_signed')) ?>
                </label>
            </div>
            <div class="span-2 adm-alert adm-alert--warn" style="display:flex;flex-direction:column;gap:6px">
                <label class="adm-check" style="color:var(--adm-text);font-weight:500">
                    <input type="checkbox" name="live_giveaway_enabled" value="1" <?= !empty($liveGiveawayEnabled) ? 'checked' : '' ?>>
                    <?= $e(t('admin.live_giveaway_enabled')) ?>
                </label>
                <span style="font-size:12px"><?= $e(t('admin.live_giveaway_legal_hint')) ?></span>
            </div>
        </div>
        <div class="adm-card-foot adm-form-actions">
            <button class="adm-btn adm-btn--primary"><?= $e(t('admin.stream_save')) ?></button>
        </div>
    </form>
</div>
