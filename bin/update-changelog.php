<?php

declare(strict_types=1);

/**
 * Собирает changelog из git log после деплоя.
 * Использование: php bin/update-changelog.php
 * Или: git pull && php bin/update-changelog.php
 */

$root = dirname(__DIR__);
chdir($root);

$appConfig = is_file($root . '/config/app.php') ? require $root . '/config/app.php' : [];
if (!empty($appConfig['timezone'])) {
    date_default_timezone_set($appConfig['timezone']);
}

$storage = $root . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storage) && !mkdir($storage, 0755, true) && !is_dir($storage)) {
    fwrite(STDERR, "Cannot create storage/\n");
    exit(1);
}

$outFile = $storage . DIRECTORY_SEPARATOR . 'changelog.json';

function git(string $args): ?string
{
    $cmd = 'git ' . $args . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return null;
    }
    return implode("\n", $out);
}

if (git('rev-parse --is-inside-work-tree') === null) {
    fwrite(STDERR, "Not a git repository.\n");
    exit(1);
}

$head = trim((string) git('rev-parse --short HEAD'));
if ($head === '') {
    fwrite(STDERR, "Cannot read HEAD.\n");
    exit(1);
}

$prev = null;
if (is_file($outFile)) {
    $old = json_decode((string) file_get_contents($outFile), true);
    if (is_array($old) && !empty($old['version']) && preg_match('/^[0-9a-f]{4,40}$/i', (string) $old['version'])) {
        $prev = (string) $old['version'];
    }
}

if ($prev !== null && $prev === $head) {
    echo "Changelog already at {$head}, skip.\n";
    exit(0);
}

$logArgs = ($prev !== null && git('rev-parse --verify ' . $prev) !== null)
    ? "log {$prev}..{$head} --pretty=format:%s --no-merges"
    : 'log -12 --pretty=format:%s --no-merges';

$rawLog = git($logArgs);
$subjects = [];
if ($rawLog !== null && trim($rawLog) !== '') {
    foreach (preg_split("/\r\n|\n|\r/", $rawLog) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^Merge\b/i', $line)) {
            continue;
        }
        // Одна строка для UI
        if (mb_strlen($line) > 140) {
            $line = rtrim(mb_substr($line, 0, 137)) . '…';
        }
        $subjects[] = $line;
        if (count($subjects) >= 12) {
            break;
        }
    }
}

if ($subjects === []) {
    $subjects[] = 'Обновление сайта (' . $head . ')';
}

$payload = [
    'version' => $head,
    'date' => date('Y-m-d H:i'),
    'items' => array_values($subjects),
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($outFile, $json . "\n") === false) {
    fwrite(STDERR, "Failed to write {$outFile}\n");
    exit(1);
}

echo "Wrote changelog {$head} (" . count($subjects) . " items) → storage/changelog.json\n";
