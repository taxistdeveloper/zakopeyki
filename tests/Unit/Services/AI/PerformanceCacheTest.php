<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Support\AiCache;
use App\Services\AI\Support\CategoryIndex;
use PHPUnit\Framework\TestCase;

final class PerformanceCacheTest extends TestCase
{
    public function testAiCacheRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/ai_cache_ut_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);
        $cache = new AiCache($dir);
        $cache->set('hello', ['a' => 1], 60);
        $this->assertSame(['a' => 1], $cache->get('hello'));
        $cache->delete('hello');
        $this->assertNull($cache->get('hello'));
    }

    public function testCategoryIndexHasLabels(): void
    {
        $idx = new CategoryIndex(new AiCache(sys_get_temp_dir() . '/ai_cat_' . bin2hex(random_bytes(3))));
        $labels = $idx->flatLabels();
        $this->assertNotEmpty($labels);
        $this->assertIsString($labels[0]);
    }

    public function testRememberCachesProducer(): void
    {
        $dir = sys_get_temp_dir() . '/ai_rem_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);
        $cache = new AiCache($dir);
        $calls = 0;
        $v1 = $cache->remember('k', 60, static function () use (&$calls) {
            $calls++;
            return 'x';
        });
        $v2 = $cache->remember('k', 60, static function () use (&$calls) {
            $calls++;
            return 'y';
        });
        $this->assertSame('x', $v1);
        $this->assertSame('x', $v2);
        $this->assertSame(1, $calls);
    }
}
