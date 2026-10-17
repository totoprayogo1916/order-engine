<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\OrderAddress;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Enums\OrderAddressType;
use Ttpryg\OrderEngine\Enums\OrderHistoryAction;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Repositories\PdoOrderAddressRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderHistoryRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\PdoOrderRepository;
use Ttpryg\OrderEngine\Services\OrderService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class PdoOrderRepositoryTest extends TestCase
{
    private PDO $pdo;

    private PdoOrderRepository $orderRepo;

    private PdoOrderItemRepository $itemRepo;

    private PdoOrderAddressRepository $addressRepo;

    private PdoOrderHistoryRepository $historyRepo;

    private OrderService $orderService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__.'/../../database/schema.sql');
        $this->pdo->exec($schema);

        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->itemRepo = new PdoOrderItemRepository($this->pdo);
        $this->addressRepo = new PdoOrderAddressRepository($this->pdo);
        $this->historyRepo = new PdoOrderHistoryRepository($this->pdo);

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->itemRepo,
            $this->addressRepo,
            $this->historyRepo,
            pdo: $this->pdo
        );
    }

    public function test_save_and_find_order_with_pdo(): void
    {
        $item = new OrderItem(
            id: 'item-pdo-1',
            orderId: 'ord-pdo-1',
            productId: 'prod-pdo-100',
            sku: 'SKU-PDO',
            name: 'Pdo Laptop',
            quantity: 1,
            unitPrice: Money::fromDecimal(12000000)
        );

        $shippingAddress = new OrderAddress(
            id: 'addr-pdo-1',
            orderId: 'ord-pdo-1',
            type: OrderAddressType::SHIPPING,
            name: 'Jane Doe',
            phone: '0899999999',
            address: 'Jl. Malioboro No. 5',
            city: 'Yogyakarta',
            province: 'DI Yogyakarta',
            postalCode: '55213'
        );

        $this->orderService->createOrder(
            id: 'ord-pdo-1',
            customerType: 'user',
            customerId: 'user-sqlite-1',
            items: [$item],
            addresses: [$shippingAddress],
            orderNumber: 'ORD-SQLITE-001',
            shippingTotal: Money::fromDecimal(25000)
        );

        $fetched = $this->orderService->getOrderByNumber('ORD-SQLITE-001');

        $this->assertNotNull($fetched);
        $this->assertEquals('ord-pdo-1', $fetched->id);
        $this->assertEquals(OrderStatus::PENDING, $fetched->status);
        $this->assertSame('12000000.00', $fetched->subtotal->toDecimal());
        $this->assertSame('12025000.00', $fetched->grandTotal->toDecimal());

        $this->assertCount(1, $fetched->items);
        $this->assertEquals('Pdo Laptop', $fetched->items[0]->name);

        $this->assertCount(1, $fetched->addresses);
        $this->assertEquals(OrderAddressType::SHIPPING, $fetched->addresses[0]->type);

        $histories = $this->historyRepo->findByOrderId('ord-pdo-1');
        $this->assertCount(1, $histories);
        $this->assertEquals(OrderHistoryAction::ORDER_CREATED, $histories[0]->action);
    }

    public function test_transaction_rollback_on_failure(): void
    {
        $this->expectException(\RuntimeException::class);

        // Intentionally corrupt process inside custom transaction to verify rollback
        $this->pdo->beginTransaction();
        try {
            $item = new OrderItem(
                id: 'item-fail-1',
                orderId: 'ord-fail-1',
                productId: 'p-1',
                name: 'Test Item',
                quantity: 1,
                unitPrice: Money::fromDecimal(10)
            );
            $this->itemRepo->save($item);

            throw new \RuntimeException('Forced database transaction failure');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
