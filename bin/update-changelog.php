<?php

declare(strict_types=1);

/**
 * Собирает changelog из git log после деплоя.
 * Использование: php bin/update-changelog.php
 * Принудительно: php bin/update-changelog.php --force
 * Или: git pull && php bin/update-changelog.php
 */

$root = dirname(__DIR__);
chdir($root);

$force = in_array('--force', $argv ?? [], true);

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

/** Первое предложение / укороченная тема коммита для модалки. */
function shortenCommit(string $line): string
{
    $line = trim($line);
    if (preg_match('/^(.+?[.!?])(\s|$)/u', $line, $m) && mb_strlen($m[1]) >= 20) {
        $line = $m[1];
    }
    if (mb_strlen($line) > 120) {
        $line = rtrim(mb_substr($line, 0, 117)) . '…';
    }
    return $line;
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
    if (is_array($old) && !empty($old['version']) && preg_match('/^[0-9a-f]{4,40}/i', (string) $old['version'])) {
        // version может быть "abc1234" или "abc1234-ru1"
        if (preg_match('/^([0-9a-f]{4,40})/i', (string) $old['version'], $vm)) {
            $prev = $vm[1];
        }
    }
}

if (!$force && $prev !== null && $prev === $head) {
    echo "Changelog already at {$head}, skip.\n";
    exit(0);
}

$logArgs = ($prev !== null && !$force && git('rev-parse --verify ' . $prev) !== null)
    ? "log {$prev}..{$head} --pretty=format:%s --no-merges"
    : 'log -8 --pretty=format:%s --no-merges';

$rawLog = git($logArgs);
$subjects = [];
if ($rawLog !== null && trim($rawLog) !== '') {
    foreach (preg_split("/\r\n|\n|\r/", $rawLog) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/^Merge\b/i', $line)) {
            continue;
        }
        if (preg_match('/\btest div\b/i', $line)) {
            continue;
        }
        $subjects[] = shortenCommit($line);
        if (count($subjects) >= 10) {
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
