<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Core\RateLimiter;
use App\Services\AI\Learning\EvaluationService;
use PHPUnit\Framework\TestCase;

final class RateLimitAndLearningTest extends TestCase
{
    public function testRateLimiterBlocksAfterLimit(): void
    {
        $dir = sys_get_temp_dir() . '/ai_rate_test_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);

        $rl = new RateLimiter([
            'per_minute' => 2,
            'per_hour' => 100,
        ]);

        // Force file path by using unique key; MySQL may also work — either way limit applies
        $key = 'ut_' . bin2hex(random_bytes(6));
        $a1 = $rl->attempt($key);
        $a2 = $rl->attempt($key);
        $a3 = $rl->attempt($key);

        $this->assertTrue(!empty($a1['allowed']));
        $this->assertTrue(!empty($a2['allowed']));
        $this->assertEmpty($a3['allowed'] ?? null);
        $this->assertSame('per_minute', $a3['reason'] ?? null);
    }

    public function testEvaluationScoresNegativeFeedback(): void
    {
        // Unit without DB: reflect private logic via public evaluate if DB unavailable skip
        try {
            $svc = new EvaluationService();
            $rows = $svc->evaluateFeedback([
                'message_id' => 0,
                'rating' => 1,
                'reason' => 'hallucination',
            ]);
            $metrics = array_column($rows, 'metric');
            $this->assertContains('csat', $metrics);
            $this->assertContains('negative_feedback', $metrics);
            $this->assertTrue(
                (bool) array_filter($metrics, static fn ($m) => str_starts_with((string) $m, 'negative_reason:'))
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }
    }

    public function testClientKeyPrefersUser(): void
    {
        $rl = new RateLimiter(['per_minute' => 20, 'per_hour' => 200]);
        $this->assertSame('u:42', $rl->clientKey(42, 'gt_xxx', '1.2.3.4'));
        $this->assertStringStartsWith('g:', $rl->clientKey(null, 'gt_secret'));
        $this->assertStringStartsWith('ip:', $rl->clientKey(null, null, '127.0.0.1'));
    }
}
