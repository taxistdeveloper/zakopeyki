<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\Core\ListingParamsExtractor;
use App\Services\AI\Memory\MemoryScorer;
use App\Services\PriceRecommendationService;
use PHPUnit\Framework\TestCase;

final class ListingAndMemoryTest extends TestCase
{
    public function testListingExtractorParsesIphoneSale(): void
    {
        $p = (new ListingParamsExtractor())->extract(
            'Хочу продать айфон 15, состояние хорошее, 256 гигов, батарея 91%, цена 280 тысяч, Алматы'
        );
        $this->assertSame('Apple', $p['brand']);
        $this->assertSame('iPhone 15', $p['model']);
        $this->assertSame(280000, $p['price']);
        $this->assertSame('Алматы', $p['location']);
        $this->assertSame('256 ГБ', $p['storage']);
        $this->assertSame('91%', $p['battery']);
    }

    public function testListingExtractorDoesNotGlueModelAndStorage(): void
    {
        $p = (new ListingParamsExtractor())->extract(
            'Хочу продать iPhone 15 256 ГБ батарея 91% за 280 тысяч в Алматы'
        );
        $this->assertSame(280000, $p['price']);
    }

    public function testMemoryScorerStoresSearchPreferences(): void
    {
        $d = (new MemoryScorer())->score(
            'Найди iPhone до 300000 в Алматы',
            'SEARCH_PRODUCT',
            ['max_price' => 300000, 'location' => 'Алматы', 'brand' => 'Apple']
        );
        $this->assertTrue($d['should_store']);
        $this->assertStringContainsString('300000', (string) $d['content']);
        $this->assertStringContainsString('Алматы', (string) $d['content']);
    }

    public function testPriceRecommendationEmptySampleHasLimitation(): void
    {
        $svc = $this->getMockBuilder(PriceRecommendationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        // Use real service — may hit DB; if no products, limitation is set
        $real = new PriceRecommendationService();
        $r = $real->recommend(['query' => 'zzz_nonexistent_product_xyz_999']);
        $this->assertArrayHasKey('limitation', $r);
        $this->assertArrayHasKey('recommended_price', $r);
        $this->assertArrayHasKey('note', $r);
    }
}
