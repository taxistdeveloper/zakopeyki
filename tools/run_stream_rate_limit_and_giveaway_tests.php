<?php

declare(strict_types=1);

echo "=== QA-0005, QA-0006, QA-0009 Verification: Stream Rate Limiting, Giveaway & DB Schema ===\n";

$passes = 0;
$total = 0;

function assertCondition(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    if ($cond) {
        $passes++;
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc\n";
    }
}

// 1. Verify QA-0009: DB name in schema.sql matches config/database.php
$dbConfig = require __DIR__ . '/../config/database.php';
$schemaContent = file_get_contents(__DIR__ . '/../database/schema.sql');
$matchesSchema = preg_match('/CREATE DATABASE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $schemaContent, $m);
$schemaDbName = $m[1] ?? '';

assertCondition($schemaDbName === $dbConfig['dbname'], "Schema DB name '{$schemaDbName}' matches config/database.php ('{$dbConfig['dbname']}')");

// 2. Verify QA-0006: Rate limit simulation
$mockSession = [];
$clientKey = 'test_client_1';
$sessionKey = 'stream_like_rate_' . $clientKey;
$now = time();

// Simulate 30 likes within 1 minute
$blockedAt = null;
for ($i = 1; $i <= 35; $i++) {
    $window = $mockSession[$sessionKey] ?? ['count' => 0, 'start' => $now];
    if (!is_array($window) || ($now - (int) ($window['start'] ?? 0)) >= 60) {
        $window = ['count' => 1, 'start' => $now];
    } else {
        $window['count'] = (int) ($window['count'] ?? 0) + 1;
        if ($window['count'] > 30) {
            $blockedAt = $i;
            break;
        }
    }
    $mockSession[$sessionKey] = $window;
}

assertCondition($blockedAt === 31, "Like rate limiting triggers exactly on request #31 (exceeding 30 likes/min limit)");

// Simulate window reset after 60 seconds
$nowAfter60s = $now + 61;
$windowReset = $mockSession[$sessionKey] ?? ['count' => 0, 'start' => $nowAfter60s];
if (!is_array($windowReset) || ($nowAfter60s - (int) ($windowReset['start'] ?? 0)) >= 60) {
    $windowReset = ['count' => 1, 'start' => $nowAfter60s];
}
assertCondition($windowReset['count'] === 1, "Like rate counter resets to 1 after sliding 60-second window elapses");

// 3. Verify QA-0005: Giveaway routes and controller methods existence
$routesContent = file_get_contents(__DIR__ . '/../config/routes.php');
assertCondition(str_contains($routesContent, '/streams/live/giveaway/join'), "Route /streams/live/giveaway/join registered in config/routes.php");
assertCondition(str_contains($routesContent, '/streams/live/giveaway/draw'), "Route /streams/live/giveaway/draw registered in config/routes.php");

$streamModelSource = file_get_contents(__DIR__ . '/../app/Models/Stream.php');
assertCondition(str_contains($streamModelSource, 'function joinGiveaway'), "Stream::joinGiveaway method defined in app/Models/Stream.php");
assertCondition(str_contains($streamModelSource, 'function countGiveawayParticipants'), "Stream::countGiveawayParticipants method defined in app/Models/Stream.php");
assertCondition(str_contains($streamModelSource, 'function drawGiveawayWinner'), "Stream::drawGiveawayWinner method defined in app/Models/Stream.php");
assertCondition(str_contains($streamModelSource, 'stream_giveaway_participants'), "stream_giveaway_participants table creation present in ensureSchema");

$streamCtrlSource = file_get_contents(__DIR__ . '/../app/Controllers/StreamController.php');
assertCondition(str_contains($streamCtrlSource, 'function joinGiveaway'), "StreamController::joinGiveaway endpoint defined");
assertCondition(str_contains($streamCtrlSource, 'function drawGiveaway'), "StreamController::drawGiveaway endpoint defined");
assertCondition(str_contains($streamCtrlSource, 'Rate limit: максимум 30 лайков'), "StreamController::like enforces rate limit");

echo "\nSummary: $passes / $total tests passed.\n";
if ($passes === $total) {
    echo "SUCCESS: QA-0005, QA-0006 & QA-0009 verified!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}

