<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\OrderAddress;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Enums\OrderAddressType;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Repositories\MemoryOrderAddressRepository;
use Ttpryg\OrderEngine\Repositories\MemoryOrderHistoryRepository;
use Ttpryg\OrderEngine\Repositories\MemoryOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\MemoryOrderRepository;
use Ttpryg\OrderEngine\Services\OrderService;
use Ttpryg\OrderEngine\ValueObjects\Money;

class OrderServiceTest extends TestCase
{
    private MemoryOrderRepository $orderRepo;

    private MemoryOrderItemRepository $itemRepo;

    private MemoryOrderAddressRepository $addressRepo;

    private MemoryOrderHistoryRepository $historyRepo;

    private OrderService $orderService;

    protected function setUp(): void
    {
        $this->orderRepo = new MemoryOrderRepository;
        $this->itemRepo = new MemoryOrderItemRepository;
        $this->addressRepo = new MemoryOrderAddressRepository;
        $this->historyRepo = new MemoryOrderHistoryRepository;

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->itemRepo,
            $this->addressRepo,
            $this->historyRepo
        );
    }

    public function test_create_order_with_product_snapshot_and_addresses(): void
    {
        $item = new OrderItem(
            id: 'item-101',
            orderId: 'order-101',
            productId: 'prod-500',
            sku: 'SKU-500',
            name: 'Original Product Name',
            quantity: 2,
            unitPrice: Money::fromDecimal(150000)
        );

        $billingAddress = new OrderAddress(
            id: 'addr-1',
            orderId: 'order-101',
            type: OrderAddressType::BILLING,
            name: 'Budi Santoso',
            phone: '08123456789',
            address: 'Jl. Merdeka No. 10',
            city: 'Jakarta Pusat',
            province: 'DKI Jakarta',
            postalCode: '10110'
        );

        $order = $this->orderService->createOrder(
            id: 'order-101',
            customerType: 'user',
            customerId: 'user-777',
            items: [$item],
            addresses: [$billingAddress],
            orderNumber: 'ORD-2026-0001',
            shippingTotal: Money::fromDecimal(20000)
        );

        $this->assertEquals('ORD-2026-0001', $order->orderNumber);
        $this->assertEquals(OrderStatus::PENDING, $order->status);
        $this->assertSame('300000.00', $order->subtotal()->toDecimal());
        $this->assertSame('320000.00', $order->grandTotal()->toDecimal());

        // Verify product snapshot integrity
        $this->assertEquals('Original Product Name', $order->items[0]->name);
        $this->assertTrue($order->items[0]->unitPrice()->equals(Money::fromDecimal(150000)));
    }

    public function test_add_remove_and_update_items(): void
    {
        $this->orderService->createOrder(
            id: 'order-102',
            customerType: 'member',
            customerId: 'M-123'
        );

        $item1 = new OrderItem(
            id: 'item-201',
            orderId: 'order-102',
            productId: 'prod-1',
            name: 'Item A',
            quantity: 1,
            unitPrice: Money::fromDecimal(50000)
        );

        $this->orderService->addItem('order-102', $item1);
        $fetched = $this->orderService->getOrder('order-102');
        $this->assertCount(1, $fetched->items);
        $this->assertSame('50000.00', $fetched->grandTotal()->toDecimal());

        // Update Quantity
        $this->orderService->updateItemQuantity('order-102', 'item-201', 3);
        $fetched = $this->orderService->getOrder('order-102');
        $this->assertSame('150000.00', $fetched->grandTotal()->toDecimal());

        // Remove Item
        $this->orderService->removeItem('order-102', 'item-201');
        $fetched = $this->orderService->getOrder('order-102');
        $this->assertCount(0, $fetched->items);
        $this->assertSame('0.00', $fetched->grandTotal()->toDecimal());
    }
}
