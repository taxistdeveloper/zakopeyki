<?php
use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\HalykEpay\Client as HalykClient;

$token = (string) ($token ?? '');
$payObject = $payObject ?? [];
$jsUrl = (string) ($jsUrl ?? '');
$orderId = (string) ($orderId ?? '');
$amount = (float) ($amount ?? 0);
$description = (string) ($description ?? '');
$amountLabel = Wallet::formatMoney((int) round($amount));
$client = new HalykClient();
if ($jsUrl === '') {
    $jsUrl = $client->jsUrl();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Halyk ePay · <?= htmlspecialchars(t('halyk_sim.title')) ?></title>
    <style>
        :root { --hb-green: #00a651; --hb-ink: #1a1a1a; --hb-muted: #6b7280; --hb-bg: #f3f4f6; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: radial-gradient(ellipse at top, #e8f8ef 0%, transparent 55%), linear-gradient(180deg, #f8faf9 0%, var(--hb-bg) 100%);
            color: var(--hb-ink);
            display: flex; align-items: center; justify-content: center;
            padding: 24px 16px;
        }
        .card {
            width: 100%; max-width: 420px; background: #fff; border-radius: 20px;
            box-shadow: 0 18px 50px rgba(0, 80, 40, 0.12);
            overflow: hidden; border: 1px solid rgba(0, 166, 81, 0.12);
        }
        .head { padding: 22px 24px 18px; background: linear-gradient(135deg, #00a651 0%, #007a3d 100%); color: #fff; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand img { height: 36px; width: auto; background: #fff; border-radius: 8px; padding: 4px 8px; }
        .badge {
            margin-top: 10px; display: inline-block; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.08em;
            background: rgba(255,255,255,0.18); padding: 4px 10px; border-radius: 999px;
        }
        .body { padding: 22px 24px 24px; }
        .amount { font-size: 34px; font-weight: 800; letter-spacing: -0.03em; margin: 0 0 4px; }
        .desc { color: var(--hb-muted); font-size: 14px; margin: 0 0 18px; line-height: 1.45; word-break: break-word; }
        .meta {
            border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px 14px;
            font-size: 12px; color: var(--hb-muted); margin-bottom: 18px;
        }
        .meta strong { color: var(--hb-ink); font-weight: 600; }
        .status { font-size: 14px; color: var(--hb-muted); text-align: center; padding: 8px 0 4px; }
        .err { color: #b91c1c; font-size: 13px; text-align: center; margin-top: 10px; display: none; }
        .note { margin-top: 14px; font-size: 11px; color: var(--hb-muted); line-height: 1.4; text-align: center; }
        .cards { margin-top: 12px; font-size: 11px; color: var(--hb-muted); line-height: 1.5; text-align: center; }
        code { font-size: 10px; background: #f3f4f6; padding: 1px 4px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <div class="brand">
                <img src="<?= ProductHelper::url('/public/assets/img/payments/halyk.png') ?>" alt="Halyk" onerror="this.style.display='none'">
                <span style="font-weight:800;letter-spacing:.04em;font-size:18px">HALYK ePay</span>
            </div>
            <span class="badge"><?= htmlspecialchars(t('halyk_sim.badge_live')) ?></span>
        </div>
        <div class="body">
            <p class="amount"><?= htmlspecialchars($amountLabel) ?></p>
            <p class="desc"><?= htmlspecialchars($description !== '' ? $description : t('halyk_sim.default_desc')) ?></p>
            <div class="meta">
                <?= htmlspecialchars(t('halyk_sim.order')) ?>:
                <strong><?= htmlspecialchars($orderId) ?></strong>
            </div>
            <p class="status" id="halyk-status"><?= htmlspecialchars(t('halyk_sim.opening')) ?></p>
            <p class="err" id="halyk-error"></p>
            <p class="note"><?= htmlspecialchars(t('halyk_sim.note_live')) ?></p>
            <p class="cards"><?= htmlspecialchars(t('halyk_sim.test_card')) ?>: <code>4405639704015096</code> / 01/27 / 321</p>
        </div>
    </div>
    <script src="<?= htmlspecialchars($jsUrl) ?>"></script>
    <script>
    (function () {
        var payObject = <?= json_encode($payObject, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var statusEl = document.getElementById('halyk-status');
        var errEl = document.getElementById('halyk-error');
        function fail(msg) {
            if (statusEl) statusEl.textContent = '';
            if (errEl) {
                errEl.style.display = 'block';
                errEl.textContent = msg || <?= json_encode(t('halyk_sim.open_failed'), JSON_UNESCAPED_UNICODE) ?>;
            }
        }
        try {
            if (!payObject || !payObject.auth || !payObject.auth.access_token) {
                fail(<?= json_encode(t('halyk_sim.no_token'), JSON_UNESCAPED_UNICODE) ?>);
                return;
            }
            if (typeof halyk === 'undefined' || typeof halyk.pay !== 'function') {
                fail(<?= json_encode(t('halyk_sim.no_sdk'), JSON_UNESCAPED_UNICODE) ?>);
                return;
            }
            // Официальная платёжная форма / cardVerification
            // https://epayment.kz/docs/cardverificationpaymentForm
            // https://epayment.kz/docs/platezhnaya-stranica
            halyk.pay(payObject);
        } catch (e) {
            fail(e && e.message ? e.message : String(e));
        }
    })();
    </script>
</body>
</html>
