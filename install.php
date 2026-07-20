<?php
/**
 * Установщик БД Zakopeyki
 * Откройте: http://localhost/zakapeiku/install.php
 * После установки удалите этот файл.
 */

$config = require __DIR__ . '/config/database.php';
$message = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'] ?? $config['host'];
    $port = $_POST['port'] ?? $config['port'];
    $user = $_POST['username'] ?? $config['username'];
    $pass = $_POST['password'] ?? $config['password'];
    $dbname = $_POST['dbname'] ?? $config['dbname'];

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbname) . '`');

        $runSql = function (PDO $pdo, string $sql): void {
            $sql = preg_replace('/^--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        };

        $runSql($pdo, file_get_contents(__DIR__ . '/database/schema.sql'));
        $runSql($pdo, file_get_contents(__DIR__ . '/database/seed.sql'));

        // Гарантируем рабочие пароли demo-аккаунтов
        $pdo->exec('USE `' . str_replace('`', '``', $dbname) . '`');
        $hash = password_hash('password', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE email IN (?, ?)');
        $stmt->execute([$hash, 'admin@zakopeyki.kz', 'user@zakopeyki.kz']);

        // Update config file
        $configPhp = "<?php\n\nreturn [\n"
            . "    'host' => " . var_export($host, true) . ",\n"
            . "    'port' => " . var_export($port, true) . ",\n"
            . "    'dbname' => " . var_export($dbname, true) . ",\n"
            . "    'username' => " . var_export($user, true) . ",\n"
            . "    'password' => " . var_export($pass, true) . ",\n"
            . "    'charset' => 'utf8mb4',\n"
            . "];\n";
        file_put_contents(__DIR__ . '/config/database.php', $configPhp);

        $ok = true;
        $message = 'База данных создана и заполнена демо-данными!';
    } catch (Throwable $e) {
        $message = 'Ошибка: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Установка Zakopeyki</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-amber-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-xl p-8 w-full max-w-lg space-y-4">
        <h1 class="text-2xl font-black">⚙ Установка MySQL</h1>
        <p class="text-sm text-gray-500">MAMP: обычно host=127.0.0.1, user=root, password=root. Порт часто 3306 или 8889.</p>

        <?php if ($message): ?>
            <div class="px-4 py-3 rounded-xl text-sm font-bold <?= $ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($ok): ?>
            <a href="index.php" class="block text-center bg-amber-500 font-black py-3 rounded-xl">Открыть сайт →</a>
            <p class="text-xs text-gray-400 text-center">Рекомендуем удалить install.php</p>
        <?php else: ?>
            <form method="post" class="space-y-3">
                <input name="host" value="<?= htmlspecialchars($config['host']) ?>" class="w-full border rounded-xl h-11 px-3 text-sm" placeholder="Host">
                <input name="port" value="<?= htmlspecialchars($config['port']) ?>" class="w-full border rounded-xl h-11 px-3 text-sm" placeholder="Port">
                <input name="dbname" value="<?= htmlspecialchars($config['dbname']) ?>" class="w-full border rounded-xl h-11 px-3 text-sm" placeholder="Database">
                <input name="username" value="<?= htmlspecialchars($config['username']) ?>" class="w-full border rounded-xl h-11 px-3 text-sm" placeholder="Username">
                <input name="password" value="<?= htmlspecialchars($config['password']) ?>" class="w-full border rounded-xl h-11 px-3 text-sm" placeholder="Password">
                <button class="w-full bg-amber-500 font-black py-3 rounded-xl">Создать БД и сиды</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
