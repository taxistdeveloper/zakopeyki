<?php
/** @var string $name */
/** @var string $ticketNumber */
/** @var string $subject */
/** @var string $ticketUrl */
/** @var string $greeting */
/** @var string $body */
/** @var string $cta */
/** @var string $hint */
/** @var string $footer */

$name = htmlspecialchars((string) ($name ?? ''), ENT_QUOTES, 'UTF-8');
$ticketNumber = htmlspecialchars((string) ($ticketNumber ?? ''), ENT_QUOTES, 'UTF-8');
$subject = htmlspecialchars((string) ($subject ?? ''), ENT_QUOTES, 'UTF-8');
$ticketUrl = htmlspecialchars((string) ($ticketUrl ?? ''), ENT_QUOTES, 'UTF-8');
$greeting = htmlspecialchars((string) ($greeting ?? ''), ENT_QUOTES, 'UTF-8');
$body = htmlspecialchars((string) ($body ?? ''), ENT_QUOTES, 'UTF-8');
$cta = htmlspecialchars((string) ($cta ?? ''), ENT_QUOTES, 'UTF-8');
$hint = htmlspecialchars((string) ($hint ?? ''), ENT_QUOTES, 'UTF-8');
$footer = htmlspecialchars((string) ($footer ?? 'zakopeyki.kz'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>zakopeyki.kz</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;-webkit-text-size-adjust:100%;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F1F5F9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background-color:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#2563EB 0%,#1D4ED8 55%,#1E3A8A 100%);padding:28px 32px 24px;">
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:28px;font-weight:800;letter-spacing:-0.5px;line-height:1;">
                                <span style="color:#FFFFFF;">za</span><span style="color:#BFDBFE;">kopeyki</span>
                            </p>
                            <p style="margin:8px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.75);">
                                zakopeyki.kz
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 32px 8px;font-family:Arial,Helvetica,sans-serif;color:#0F172A;">
                            <p style="margin:0 0 12px;font-size:20px;font-weight:700;line-height:1.3;color:#0F172A;">
                                <?= $greeting ?>
                            </p>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
                                <?= $body ?>
                            </p>
                            <?php if ($ticketNumber !== ''): ?>
                            <p style="margin:0 0 20px;font-size:14px;line-height:1.5;color:#0F172A;">
                                <strong style="display:inline-block;padding:8px 12px;border-radius:10px;background:#EFF6FF;color:#1D4ED8;font-family:Arial,Helvetica,sans-serif;">
                                    <?= $ticketNumber ?>
                                </strong>
                            </p>
                            <?php endif; ?>
                            <?php if ($subject !== ''): ?>
                            <p style="margin:0 0 24px;font-size:13px;line-height:1.5;color:#64748B;">
                                <?= $subject ?>
                            </p>
                            <?php endif; ?>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 28px;">
                                <tr>
                                    <td align="center" style="border-radius:12px;background-color:#2563EB;">
                                        <a href="<?= $ticketUrl ?>"
                                           style="display:inline-block;padding:14px 28px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#ffffff;text-decoration:none;border-radius:12px;">
                                            <?= $cta ?>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <?php if ($hint !== ''): ?>
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#64748B;">
                                <?= $hint ?>
                            </p>
                            <?php endif; ?>
                            <p style="margin:16px 0 8px;font-size:11px;line-height:1.5;word-break:break-all;">
                                <a href="<?= $ticketUrl ?>" style="color:#2563EB;text-decoration:underline;"><?= $ticketUrl ?></a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 28px;border-top:1px solid #E2E8F0;font-family:Arial,Helvetica,sans-serif;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#94A3B8;text-align:center;">
                                <?= $footer ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
