<?php
use App\Helpers\ProductHelper;
use App\Models\Wallet;

$token = (string) ($token ?? '');
$orderId = (string) ($orderId ?? '');
$amount = (int) ($amount ?? 0);
$description = (string) ($description ?? '');
$amountLabel = Wallet::formatMoney($amount);
$action = ProductHelper::url('/payments/halyk/sim/' . rawurlencode($token) . '/decide');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Halyk ePay · <?= htmlspecialchars(t('halyk_sim.title')) ?></title>
    <style>
        :root {
            --hb-green: #00a651;
            --hb-green-dark: #008f46;
            --hb-ink: #1a1a1a;
            --hb-muted: #6b7280;
            --hb-line: #e5e7eb;
            --hb-bg: #f3f4f6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, sans-serif;
            background:
                radial-gradient(ellipse at top, #e8f8ef 0%, transparent 55%),
                linear-gradient(180deg, #f8faf9 0%, var(--hb-bg) 100%);
            color: var(--hb-ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 18px 50px rgba(0, 80, 40, 0.12);
            overflow: hidden;
            border: 1px solid rgba(0, 166, 81, 0.12);
        }
        .head {
            padding: 22px 24px 18px;
            background: linear-gradient(135deg, #00a651 0%, #007a3d 100%);
            color: #fff;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand img { height: 36px; width: auto; background: #fff; border-radius: 8px; padding: 4px 8px; }
        .brand-fallback {
            font-weight: 800;
            letter-spacing: 0.04em;
            font-size: 18px;
        }
        .badge {
            margin-top: 10px;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: rgba(255,255,255,0.18);
            padding: 4px 10px;
            border-radius: 999px;
        }
        .body { padding: 22px 24px 24px; }
        .amount {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 4px;
        }
        .desc {
            color: var(--hb-muted);
            font-size: 14px;
            margin: 0 0 18px;
            line-height: 1.45;
            word-break: break-word;
        }
        .meta {
            border: 1px solid var(--hb-line);
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 12px;
            color: var(--hb-muted);
            margin-bottom: 18px;
        }
        .meta strong { color: var(--hb-ink); font-weight: 600; }
        .actions { display: grid; gap: 10px; }
        button {
            appearance: none;
            border: 0;
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .12s ease, opacity .12s ease;
        }
        button:active { transform: scale(0.98); }
        .pay {
            background: var(--hb-green);
            color: #fff;
        }
        .pay:hover { background: var(--hb-green-dark); }
        .fail {
            background: #fff;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .note {
            margin-top: 14px;
            font-size: 11px;
            color: var(--hb-muted);
            line-height: 1.4;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <div class="brand">
                <img src="<?= ProductHelper::url('/public/assets/img/payments/halyk.png') ?>" alt="Halyk" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <span class="brand-fallback" style="display:none">HALYK BANK</span>
            </div>
            <span class="badge"><?= htmlspecialchars(t('halyk_sim.badge')) ?></span>
        </div>
        <div class="body">
            <p class="amount"><?= htmlspecialchars($amountLabel) ?></p>
            <p class="desc"><?= htmlspecialchars($description !== '' ? $description : t('halyk_sim.default_desc')) ?></p>
            <div class="meta">
                <?= htmlspecialchars(t('halyk_sim.order')) ?>:
                <strong><?= htmlspecialchars($orderId) ?></strong>
            </div>
            <form method="post" action="<?= htmlspecialchars($action) ?>" class="actions">
                <?= csrf_field() ?>
                <button type="submit" name="result" value="1" class="pay"><?= htmlspecialchars(t('halyk_sim.pay')) ?></button>
                <button type="submit" name="result" value="0" class="fail"><?= htmlspecialchars(t('halyk_sim.fail')) ?></button>
            </form>
            <p class="note"><?= htmlspecialchars(t('halyk_sim.note')) ?></p>
        </div>
    </div>
</body>
</html>
