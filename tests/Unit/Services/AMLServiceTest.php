<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AMLService;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AMLServiceTest extends TestCase
{
    private PDO|MockObject $pdoMock;
    private PDOStatement|MockObject $stmtMock;

    private const VALID_IIN = '990101300013';
    private const VALID_BIN = '090140012343';
    private const INVALID_CHECKSUM = '990101300019';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
    }

    /**
     * @dataProvider iinValidationProvider
     */
    public function testValidateIinFormat(string $iin, bool $expected): void
    {
        $service = new AMLService($this->pdoMock);
        $this->assertSame($expected, $service->validateIinFormat($iin));
    }

    public static function iinValidationProvider(): array
    {
        return [
            'valid' => [self::VALID_IIN, true],
            'short' => ['12345', false],
            'letters' => ['99010130001A', false],
            'bad checksum' => [self::INVALID_CHECKSUM, false],
            'empty' => ['', false],
            'spaces stripped valid' => ['990 101 300 013', true],
        ];
    }

    public function testValidateBinFormat(): void
    {
        $service = new AMLService($this->pdoMock);
        $this->assertTrue($service->validateBinFormat(self::VALID_BIN));
        $this->assertFalse($service->validateIinFormat(self::VALID_BIN));
        $this->assertTrue($service->validateBusinessTaxId(self::VALID_BIN, 'too'));
        $this->assertTrue($service->validateBusinessTaxId(self::VALID_IIN, 'ip'));
        $this->assertFalse($service->validateBusinessTaxId(self::VALID_IIN, 'too'));
    }

    public function testIsBlacklistedAcceptsBinThatIsNotIin(): void
    {
        $this->stmtMock->expects($this->once())->method('execute')->with(['iin' => self::VALID_BIN]);
        $this->stmtMock->expects($this->once())->method('fetchColumn')->willReturn(1);
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('aml_blacklisted_persons'))
            ->willReturn($this->stmtMock);

        $service = new AMLService($this->pdoMock, null);
        $this->assertTrue($service->isBlacklisted(self::VALID_BIN));
    }

    public function testUserListingStatusUsesBinForBusiness(): void
    {
        $this->assertSame('ok', AMLService::userListingStatus([
            'account_type' => 'business',
            'business_status' => 'verified',
            'bin' => self::VALID_BIN,
            'aml_status' => AMLService::STATUS_CLEAR,
        ]));
        $this->assertSame('needs_iin', AMLService::userListingStatus([
            'account_type' => 'business',
            'business_status' => 'verified',
            'iin' => self::VALID_IIN,
            'aml_status' => AMLService::STATUS_CLEAR,
        ]));
    }

    public function testIsBlacklistedQueriesMysqlWhenRedisMissing(): void
    {
        $this->stmtMock->expects($this->once())->method('execute')->with(['iin' => self::VALID_IIN]);
        $this->stmtMock->expects($this->once())->method('fetchColumn')->willReturn(1);
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('aml_blacklisted_persons'))
            ->willReturn($this->stmtMock);

        $service = new AMLService($this->pdoMock, null);
        $this->assertTrue($service->isBlacklisted(self::VALID_IIN));
    }

    public function testIsBlacklistedThrowsOnInvalidIin(): void
    {
        $service = new AMLService($this->pdoMock);
        $this->expectException(InvalidArgumentException::class);
        $service->isBlacklisted('123');
    }

    public function testIsBlacklistedUsesRedisWhenKeyExists(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not installed');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('exists')->with('aml:blacklisted_iins')->willReturn(true);
        $redis->expects($this->once())
            ->method('sIsMember')
            ->with('aml:blacklisted_iins', self::VALID_IIN)
            ->willReturn(true);
        $this->pdoMock->expects($this->never())->method('prepare');

        $service = new AMLService($this->pdoMock, $redis);
        $this->assertTrue($service->isBlacklisted(self::VALID_IIN));
    }

    public function testIsBlacklistedFallsBackToMysqlWhenRedisKeyMissing(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not installed');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('exists')->willReturn(false);
        $redis->expects($this->never())->method('sIsMember');
        $this->stmtMock->method('fetchColumn')->willReturn(false);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $service = new AMLService($this->pdoMock, $redis);
        $this->assertFalse($service->isBlacklisted(self::VALID_IIN));
    }
}
