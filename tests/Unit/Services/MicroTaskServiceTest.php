<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AcquiringService;
use App\Services\MicroTaskService;
use App\Services\UnskilledTaskValidator;
use App\Services\WalletService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

if (!class_exists(\Redis::class)) {
    class RedisStubForTests
    {
        public function set(string $key, mixed $value, mixed $options = null): bool
        {
            return false;
        }
    }
}

class MicroTaskServiceTest extends TestCase
{
    private PDO|MockObject $pdoMock;
    private object $redisMock;
    private UnskilledTaskValidator|MockObject $validatorMock;
    private WalletService|MockObject $walletMock;
    private AcquiringService|MockObject $acquiringMock;
    private MicroTaskService $service;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(PDO::class);
        $this->redisMock = class_exists(\Redis::class)
            ? $this->createMock(\Redis::class)
            : $this->getMockBuilder(\stdClass::class)->addMethods(['set'])->getMock();
        $this->validatorMock = $this->createMock(UnskilledTaskValidator::class);
        $this->walletMock = $this->createMock(WalletService::class);
        $this->acquiringMock = $this->createMock(AcquiringService::class);

        $this->service = new MicroTaskService(
            $this->pdoMock,
            $this->redisMock,
            $this->validatorMock,
            $this->walletMock,
            $this->acquiringMock
        );
    }

    public function testSubmitOfferInstantMatchSuccess(): void
    {
        $executorId = 2;
        $taskId = 10;
        $initialPrice = 1000.00;
        $expectedDiscountPrice = 800.00;

        $stmtCountMock = $this->createMock(PDOStatement::class);
        $stmtCountMock->method('fetchColumn')->willReturn(1);

        $stmtTaskMock = $this->createMock(PDOStatement::class);
        $stmtTaskMock->method('fetch')->willReturn([
            'id' => $taskId,
            'customer_id' => 1,
            'initial_price' => $initialPrice,
            'status' => 'open',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ]);

        $stmtLockMock = $this->createMock(PDOStatement::class);
        $stmtLockMock->method('rowCount')->willReturn(1);
        $stmtOfferMock = $this->createMock(PDOStatement::class);
        $stmtOthersMock = $this->createMock(PDOStatement::class);
        $stmtOthersMock->method('fetchAll')->willReturn([]);

        $this->pdoMock->method('prepare')->willReturnCallback(function (string $query) use (
            $stmtCountMock,
            $stmtTaskMock,
            $stmtLockMock,
            $stmtOfferMock,
            $stmtOthersMock
        ) {
            if (str_contains($query, 'COUNT(*)')) {
                return $stmtCountMock;
            }
            if (str_contains($query, 'SELECT * FROM `micro_tasks`')) {
                return $stmtTaskMock;
            }
            if (str_contains($query, 'UPDATE `micro_tasks`')) {
                return $stmtLockMock;
            }
            if (str_contains($query, 'INSERT INTO `micro_task_offers`')) {
                return $stmtOfferMock;
            }
            if (str_contains($query, 'SELECT `executor_id` FROM `micro_task_offers`')) {
                return $stmtOthersMock;
            }

            return $this->createMock(PDOStatement::class);
        });

        $this->walletMock->expects($this->once())
            ->method('holdResponseFee')
            ->with($executorId, 50.00)
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('set')
            ->with("micro_task_lock_{$taskId}", (string) $executorId, ['NX', 'EX' => 10])
            ->willReturn(true);

        $this->walletMock->expects($this->once())
            ->method('chargeResponseFee')
            ->with($executorId, $taskId);

        $this->acquiringMock->expects($this->once())
            ->method('adjustHoldAmount')
            ->with($taskId, $expectedDiscountPrice);

        $this->pdoMock->expects($this->once())->method('beginTransaction');
        $this->pdoMock->expects($this->once())->method('commit');

        $result = $this->service->submitOffer($executorId, $taskId, 'discount_20');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['instant_matched']);
        $this->assertEquals($expectedDiscountPrice, $result['final_price']);
    }

    public function testSubmitOfferInstantMatchFallbackWhenRedisLockFails(): void
    {
        $executorId = 3;
        $taskId = 10;
        $initialPrice = 1000.00;

        $stmtCountMock = $this->createMock(PDOStatement::class);
        $stmtCountMock->method('fetchColumn')->willReturn(0);

        $stmtTaskMock = $this->createMock(PDOStatement::class);
        $stmtTaskMock->method('fetch')->willReturn([
            'id' => $taskId,
            'customer_id' => 1,
            'initial_price' => $initialPrice,
            'status' => 'open',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ]);

        $stmtInsertOfferMock = $this->createMock(PDOStatement::class);

        $this->pdoMock->method('prepare')->willReturnCallback(function (string $query) use (
            $stmtCountMock,
            $stmtTaskMock,
            $stmtInsertOfferMock
        ) {
            if (str_contains($query, 'COUNT(*)')) {
                return $stmtCountMock;
            }
            if (str_contains($query, 'SELECT * FROM `micro_tasks`')) {
                return $stmtTaskMock;
            }
            if (str_contains($query, 'INSERT INTO `micro_task_offers`')) {
                return $stmtInsertOfferMock;
            }

            return $this->createMock(PDOStatement::class);
        });

        $this->walletMock->method('holdResponseFee')->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('set')
            ->with("micro_task_lock_{$taskId}", (string) $executorId, ['NX', 'EX' => 10])
            ->willReturn(false);

        $result = $this->service->submitOffer($executorId, $taskId, 'discount_20');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['instant_matched']);
        $this->assertEquals(800.00, $result['proposed_price']);
    }

    public function testSubmitOfferRollbackOnExceptionDuringInstantMatch(): void
    {
        $executorId = 2;
        $taskId = 10;

        $stmtCountMock = $this->createMock(PDOStatement::class);
        $stmtCountMock->method('fetchColumn')->willReturn(0);

        $stmtTaskMock = $this->createMock(PDOStatement::class);
        $stmtTaskMock->method('fetch')->willReturn([
            'id' => $taskId,
            'customer_id' => 1,
            'initial_price' => 1000.00,
            'status' => 'open',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ]);

        $stmtLockMock = $this->createMock(PDOStatement::class);
        $stmtLockMock->method('execute')->willThrowException(new RuntimeException('Database deadlock'));

        $this->pdoMock->method('prepare')->willReturnCallback(function (string $query) use (
            $stmtCountMock,
            $stmtTaskMock,
            $stmtLockMock
        ) {
            if (str_contains($query, 'COUNT(*)')) {
                return $stmtCountMock;
            }
            if (str_contains($query, 'SELECT * FROM `micro_tasks`')) {
                return $stmtTaskMock;
            }
            if (str_contains($query, 'UPDATE `micro_tasks`')) {
                return $stmtLockMock;
            }

            return $this->createMock(PDOStatement::class);
        });

        $this->walletMock->method('holdResponseFee')->willReturn(true);
        $this->redisMock->method('set')->willReturn(true);

        $this->pdoMock->method('inTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack');

        $this->walletMock->expects($this->once())
            ->method('refundResponseFee')
            ->with($executorId, $taskId);

        $result = $this->service->submitOffer($executorId, $taskId, 'discount_20');

        $this->assertFalse($result['success']);
        $this->assertEquals('Ошибка применения Instant Match.', $result['error']);
    }
}
