<?php

/**
 * Почта для сброса пароля и системных писем.
 *
 * driver:
 *   - mail  — PHP mail() (прод; нужен настроенный sendmail/SMTP на сервере)
 *   - log   — пишет письмо в storage/mail.log (удобно на локали MAMP)
 *
 * На локали: MAIL_DRIVER=log или debug=true в config/app.php (дублирует в лог).
 * Для продакшена: MAIL_DRIVER=mail и MAIL_FROM через окружение.
 */
return [
    'driver' => getenv('MAIL_DRIVER') ?: 'log',
    'from_address' => getenv('MAIL_FROM') ?: 'noreply@zakopeyki.kz',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],
];
