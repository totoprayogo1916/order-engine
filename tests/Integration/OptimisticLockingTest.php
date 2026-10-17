<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Exceptions\ConcurrencyException;
use Ttpryg\OrderEngine\Repositories\PdoOrderAddressRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderHistoryRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderRepository;
use Ttpryg\OrderEngine\Services\OrderService;
use Ttpryg\OrderEngine\Services\OrderStatusService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class OptimisticLockingTest extends TestCase
{
    private PDO $pdo;

    private PdoOrderRepository $orderRepo;

    private OrderService $orderService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(__DIR__.'/../../database/schema.sql'));

        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->orderService = new OrderService(
            $this->orderRepo,
            new PdoOrderItemRepository($this->pdo),
            new PdoOrderAddressRepository($this->pdo),
            new PdoOrderHistoryRepository($this->pdo),
            pdo: $this->pdo
        );

        $this->orderService->createOrder(
            id: 'ord-lock-1',
            customerType: 'user',
            customerId: 'user-lock-1',
            orderNumber: 'ORD-LOCK-001',
            shippingTotal: Money::fromDecimal(10000)
        );
    }

    public function test_fresh_order_has_version_one(): void
    {
        $order = $this->orderRepo->findById('ord-lock-1');

        $this->assertNotNull($order);
        $this->assertSame(1, $order->version);
    }

    public function test_successful_update_increments_version_in_database_and_memory(): void
    {
        $order = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($order);

        $order->note = 'revised by admin';
        $this->orderRepo->save($order);

        $this->assertSame(2, $order->version);

        $persisted = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($persisted);
        $this->assertSame(2, $persisted->version);
        $this->assertSame('revised by admin', $persisted->note);
    }

    public function test_stale_copy_cannot_overwrite_fresh_update(): void
    {
        $processA = $this->orderRepo->findById('ord-lock-1');
        $processB = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($processA);
        $this->assertNotNull($processB);
        $this->assertSame($processA->version, $processB->version);

        // Process A commits first.
        $processA->note = 'updated by process A';
        $this->orderRepo->save($processA);

        // Process B still holds the stale version and must be rejected.
        $processB->note = 'updated by process B';

        try {
            $this->orderRepo->save($processB);
            $this->fail('Expected ConcurrencyException was not thrown.');
        } catch (ConcurrencyException $exception) {
            $this->assertStringContainsString('ord-lock-1', $exception->getMessage());
        }

        $persisted = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($persisted);
        $this->assertSame('updated by process A', $persisted->note);
        $this->assertSame(2, $persisted->version);
    }

    public function test_status_transition_increments_version(): void
    {
        $statusService = new OrderStatusService($this->orderRepo, new PdoOrderHistoryRepository($this->pdo), pdo: $this->pdo);

        $confirmed = $statusService->confirm('ord-lock-1', actorId: 'admin-1');

        $this->assertSame(OrderStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(2, $confirmed->version);

        $persisted = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($persisted);
        $this->assertSame(2, $persisted->version);
    }

    public function test_order_service_reload_picks_up_latest_version_before_saving(): void
    {
        $order = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($order);
        $order->note = 'first write';
        $this->orderRepo->save($order);

        // OrderService::getOrder() hydrates a fresh copy with the latest version,
        // so its subsequent saves keep succeeding.
        $reloaded = $this->orderService->getOrder('ord-lock-1');
        $this->assertNotNull($reloaded);
        $this->assertSame(2, $reloaded->version);

        $reloaded->note = 'second write';
        $this->orderRepo->save($reloaded);

        $persisted = $this->orderRepo->findById('ord-lock-1');
        $this->assertNotNull($persisted);
        $this->assertSame('second write', $persisted->note);
        $this->assertSame(3, $persisted->version);
    }

    public function test_version_below_one_is_normalized_to_one(): void
    {
        $order = new Order(
            id: 'ord-lock-9',
            orderNumber: 'ORD-LOCK-009',
            customerType: 'user',
            customerId: 'user-lock-9',
            version: 0
        );

        $this->assertSame(1, $order->version);
    }
}
