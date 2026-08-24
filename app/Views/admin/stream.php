<?php
use App\Helpers\ProductHelper;
$input = 'w-full h-11 px-3.5 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="max-w-3xl mx-auto pb-16 space-y-5">
    <div>
        <a href="<?= ProductHelper::url('/admin') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('admin.title')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars(t('admin.stream_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.stream_hint')) ?></p>
    </div>
    <?php if (!empty($flash)): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= ProductHelper::url('/admin/stream') ?>" class="space-y-4 rounded-2xl border border-black/[0.06] p-5 bg-white dark:bg-white/[0.04]">
        <?= csrf_field() ?>
        <div>
            <label class="block text-xs font-bold mb-1">Account ID</label>
            <input name="cf_stream_account_id" class="<?= $input ?>" value="<?= htmlspecialchars((string) ($accountId ?? '')) ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1">API Token <?= !empty($hasToken) ? '(' . htmlspecialchars(t('admin.stream_saved_secret')) . ')' : '' ?></label>
            <input type="password" name="cf_stream_api_token" class="<?= $input ?>" autocomplete="new-password" placeholder="<?= !empty($hasToken) ? '••••••••' : '' ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1">Customer subdomain</label>
            <input name="cf_stream_customer_subdomain" class="<?= $input ?>" placeholder="customer-xxxxx.cloudflarestream.com" value="<?= htmlspecialchars((string) ($customerSubdomain ?? '')) ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1">Signing Key ID</label>
            <input name="cf_stream_signing_key_id" class="<?= $input ?>" value="<?= htmlspecialchars((string) ($signingKeyId ?? '')) ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1">Signing Key PEM <?= !empty($hasPem) ? '(' . htmlspecialchars(t('admin.stream_saved_secret')) . ')' : '' ?></label>
            <textarea name="cf_stream_signing_key_pem" rows="6" class="<?= $input ?> h-auto py-3 font-mono text-xs" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
        </div>
        <div>
            <label class="block text-xs font-bold mb-1">Webhook secret <?= !empty($hasWebhook) ? '(' . htmlspecialchars(t('admin.stream_saved_secret')) . ')' : '' ?></label>
            <input type="password" name="cf_stream_webhook_secret" class="<?= $input ?>">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="cf_stream_require_signed" value="1" <?= !empty($requireSigned) ? 'checked' : '' ?>>
            <?= htmlspecialchars(t('admin.stream_require_signed')) ?>
        </label>
        <p class="text-[12px] text-gray-400"><?= htmlspecialchars(t('admin.stream_webhook_url')) ?>: <?= htmlspecialchars(ProductHelper::url('/webhooks/cloudflare/stream')) ?></p>
        <button class="h-11 px-5 rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('admin.stream_save')) ?></button>
    </form>
</section>
