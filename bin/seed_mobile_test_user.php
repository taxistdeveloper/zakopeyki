<?php

declare(strict_types=1);

/**
 * Локальный тестовый пользователь для мобильного приложения (только dev/local!).
 *   php bin/seed_mobile_test_user.php
 * Пароль — тестовое значение, не использовать в production.
 */

require __DIR__ . '/bootstrap.php';

if (($GLOBALS['appConfig']['env'] ?? 'production') === 'production') {
    fwrite(STDERR, "Refusing to seed test user in production env\n");
    exit(1);
}

const MOBILE_TEST_EMAIL = 'mobile.test@zakopeyki.local';
const MOBILE_TEST_PASSWORD = 'ZkMobile-Test-2026';

$db = \App\Core\Database::connect();
$hash = password_hash(MOBILE_TEST_PASSWORD, PASSWORD_DEFAULT);
$stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([MOBILE_TEST_EMAIL]);
$id = $stmt->fetchColumn();
if ($id) {
    $db->prepare('UPDATE users SET password = ?, site_access = 1, blocked_at = NULL WHERE id = ?')->execute([$hash, $id]);
} else {
    $db->prepare(
        "INSERT INTO users (name, email, password, role, site_access, email_verified_at, created_at)
         VALUES ('Mobile Tester', ?, ?, 'user', 1, NOW(), NOW())"
    )->execute([MOBILE_TEST_EMAIL, $hash]);
    $id = $db->lastInsertId();
}
echo "Mobile test user ready: id={$id}, email=" . MOBILE_TEST_EMAIL . " (password in this file)\n";
