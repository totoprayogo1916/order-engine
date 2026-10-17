<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ttpryg\OrderEngine\Contracts\OrderHistoryRepositoryInterface;
use Ttpryg\OrderEngine\Entities\OrderHistory;
use Ttpryg\OrderEngine\Enums\OrderHistoryAction;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Repositories\PdoOrderAddressRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderHistoryRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderRepository;
use Ttpryg\OrderEngine\Services\OrderService;
use Ttpryg\OrderEngine\Services\OrderStatusService;

class OrderStatusServiceTransactionTest extends TestCase
{
    private PDO $pdo;

    private PdoOrderRepository $orderRepo;

    private PdoOrderHistoryRepository $historyRepo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(__DIR__.'/../../database/schema.sql'));

        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->historyRepo = new PdoOrderHistoryRepository($this->pdo);

        $orderService = new OrderService(
            $this->orderRepo,
            new PdoOrderItemRepository($this->pdo),
            new PdoOrderAddressRepository($this->pdo),
            $this->historyRepo,
            pdo: $this->pdo
        );

        $orderService->createOrder(
            id: 'ord-tx-1',
            customerType: 'user',
            customerId: 'user-tx-1',
            orderNumber: 'ORD-TX-001'
        );
    }

    public function test_status_change_persists_order_and_history_atomically(): void
    {
        $statusService = new OrderStatusService($this->orderRepo, $this->historyRepo, pdo: $this->pdo);

        $statusService->confirm('ord-tx-1', actorId: 'admin-1');

        $persisted = $this->orderRepo->findById('ord-tx-1');
        $this->assertNotNull($persisted);
        $this->assertSame(OrderStatus::CONFIRMED, $persisted->status);

        $histories = $this->historyRepo->findByOrderId('ord-tx-1');
        $this->assertCount(2, $histories);

        $statusChanged = $this->findByAction($histories, OrderHistoryAction::STATUS_CHANGED);
        $this->assertNotNull($statusChanged);
        $this->assertSame(OrderStatus::PENDING, $statusChanged->fromStatus);
        $this->assertSame(OrderStatus::CONFIRMED, $statusChanged->toStatus);
        $this->assertSame('admin-1', $statusChanged->actorId);

        $this->assertFalse($this->pdo->inTransaction());
    }

    public function test_status_update_is_rolled_back_when_history_write_fails(): void
    {
        $failingHistoryRepo = new class implements OrderHistoryRepositoryInterface
        {
            public function save(OrderHistory $history): void
            {
                throw new RuntimeException('Forced history write failure');
            }

            /**
             * @return OrderHistory[]
             */
            public function findByOrderId(string $orderId): array
            {
                return [];
            }
        };

        $statusService = new OrderStatusService($this->orderRepo, $failingHistoryRepo, pdo: $this->pdo);

        try {
            $statusService->confirm('ord-tx-1', actorId: 'admin-1');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced history write failure', $exception->getMessage());
        }

        // Status order di database harus tetap PENDING karena transaksi dibatalkan.
        $persisted = $this->orderRepo->findById('ord-tx-1');
        $this->assertNotNull($persisted);
        $this->assertSame(OrderStatus::PENDING, $persisted->status);

        // Audit trail STATUS_CHANGED tidak boleh tertulis setelah rollback.
        $histories = $this->historyRepo->findByOrderId('ord-tx-1');
        $this->assertCount(1, $histories);
        $this->assertSame(OrderHistoryAction::ORDER_CREATED, $histories[0]->action);

        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * @param  OrderHistory[]  $histories
     */
    private function findByAction(array $histories, OrderHistoryAction $action): ?OrderHistory
    {
        foreach ($histories as $history) {
            if ($history->action === $action) {
                return $history;
            }
        }

        return null;
    }
}
