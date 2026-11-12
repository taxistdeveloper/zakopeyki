<?php
/** @var string $result success|failure|expired */
$result = in_array($result ?? '', ['success', 'failure', 'expired'], true) ? $result : 'failure';
$deepLink = 'zakopeyki://payment-result?status=' . rawurlencode($result);
$title = [
    'success' => t('mobile_pay.return_success'),
    'failure' => t('mobile_pay.return_failure'),
    'expired' => t('mobile_pay.return_expired'),
][$result];
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(\App\Core\Lang::htmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars($title) ?> · Zakopeyki.kz</title>
    <style>
        body{font-family:-apple-system,system-ui,Segoe UI,Roboto,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;background:#f8fafc;color:#0f172a}
        .card{max-width:420px;padding:32px 24px;text-align:center}
        h1{font-size:20px;margin:0 0 12px} p{color:#475569;line-height:1.5}
        a.btn{display:inline-block;margin-top:16px;padding:14px 24px;border-radius:14px;background:#2563EB;color:#fff;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
<div class="card">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars(t('mobile_pay.return_hint')) ?></p>
    <a class="btn" href="<?= htmlspecialchars($deepLink) ?>"><?= htmlspecialchars(t('mobile_pay.return_button')) ?></a>
</div>
<script>
    // Автоматический возврат в приложение (окно оплаты закроется, приложение проверит статус заказа на сервере)
    window.location.replace(<?= json_encode($deepLink) ?>);
</script>
</body>
</html>
