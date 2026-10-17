<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Repositories\MemoryOrderAddressRepository;
use Ttpryg\OrderEngine\Repositories\MemoryOrderHistoryRepository;
use Ttpryg\OrderEngine\Repositories\MemoryOrderItemRepository;
use Ttpryg\OrderEngine\Repositories\MemoryOrderRepository;
use Ttpryg\OrderEngine\Services\OrderCalculationService;
use Ttpryg\OrderEngine\Services\OrderService;
use Ttpryg\OrderEngine\Tests\Fixtures\FeeApplyingCalculationService;
use Ttpryg\OrderEngine\ValueObjects\Money;

/**
 * Membuktikan OrderCalculationService adalah satu-satunya sumber kalkulasi:
 * formula tidak diulang di entity dan kalkulator yang di-inject OrderService
 * benar-benar dipakai oleh order yang dibuat/di-hydrate.
 */
class OrderCalculationIntegrationTest extends TestCase
{
    private OrderService $orderService;

    protected function setUp(): void
    {
        $this->orderService = new OrderService(
            new MemoryOrderRepository,
            new MemoryOrderItemRepository,
            new MemoryOrderAddressRepository,
            new MemoryOrderHistoryRepository,
            calculationService: new FeeApplyingCalculationService
        );
    }

    public function test_injected_calculator_applies_to_created_order_totals(): void
    {
        $order = $this->orderService->createOrder(
            id: 'ord-calc-1',
            customerType: 'user',
            customerId: 'user-calc-1',
            items: [$this->makeItem('item-calc-1', 'ord-calc-1', quantity: 2)],
            orderNumber: 'ORD-CALC-001'
        );

        // Dasar 2 × Rp 10.000,00 + iuran item Rp 0,50
        $this->assertSame('20000.50', $order->subtotal->toDecimal());
        // + biaya layanan order Rp 10,00
        $this->assertSame('20010.50', $order->grandTotal->toDecimal());
        $this->assertSame('20000.50', $order->items[0]->subtotal->toDecimal());
    }

    public function test_injected_calculator_applies_after_rehydration(): void
    {
        $this->orderService->createOrder(
            id: 'ord-calc-2',
            customerType: 'user',
            customerId: 'user-calc-2',
            items: [$this->makeItem('item-calc-2', 'ord-calc-2', quantity: 2)],
            orderNumber: 'ORD-CALC-002'
        );

        $reloaded = $this->orderService->getOrder('ord-calc-2');
        $this->assertNotNull($reloaded);
        $this->assertSame('20000.50', $reloaded->subtotal->toDecimal());
        $this->assertSame('20010.50', $reloaded->grandTotal->toDecimal());
        $this->assertSame('20000.50', $reloaded->items[0]->subtotal->toDecimal());
    }

    public function test_injected_calculator_applies_after_quantity_change(): void
    {
        $this->orderService->createOrder(
            id: 'ord-calc-3',
            customerType: 'user',
            customerId: 'user-calc-3',
            items: [$this->makeItem('item-calc-3', 'ord-calc-3', quantity: 2)],
            orderNumber: 'ORD-CALC-003'
        );

        $updated = $this->orderService->updateItemQuantity('ord-calc-3', 'item-calc-3', 3);
        $this->assertSame('30000.50', $updated->subtotal->toDecimal());
        $this->assertSame('30010.50', $updated->grandTotal->toDecimal());

        // Item yang tersimpan memakai kalkulator yang sama (bukan logika duplikat).
        $reloaded = $this->orderService->getOrder('ord-calc-3');
        $this->assertNotNull($reloaded);
        $this->assertSame('30000.50', $reloaded->items[0]->subtotal->toDecimal());
        $this->assertSame('30010.50', $reloaded->grandTotal->toDecimal());
    }

    public function test_order_can_swap_calculation_service_directly(): void
    {
        $order = new Order(
            id: 'ord-calc-4',
            orderNumber: 'ORD-CALC-004',
            customerType: 'user',
            customerId: 'user-calc-4'
        );
        $order->addItem($this->makeItem('item-calc-4', 'ord-calc-4', quantity: 1));

        $this->assertSame('10000.00', $order->subtotal->toDecimal());
        $this->assertSame('10000.00', $order->grandTotal->toDecimal());

        $order->setCalculationService(new FeeApplyingCalculationService)->recalculateTotals();

        $this->assertSame('10000.50', $order->subtotal->toDecimal());
        $this->assertSame('10010.50', $order->grandTotal->toDecimal());
    }

    public function test_calculate_subtotal_refreshes_item_subtotals(): void
    {
        $item = $this->makeItem('item-calc-5', 'ord-calc-5', quantity: 2);
        $item->subtotal = Money::fromDecimal(999999);

        $subtotal = (new OrderCalculationService)->calculateSubtotal([$item]);

        $this->assertSame('20000.00', $subtotal->toDecimal());
        $this->assertSame('20000.00', $item->subtotal->toDecimal());
    }

    private function makeItem(string $id, string $orderId, int $quantity): OrderItem
    {
        return new OrderItem(
            id: $id,
            orderId: $orderId,
            productId: 'prod-calc',
            name: 'Calculated Product',
            quantity: $quantity,
            unitPrice: 10000
        );
    }
}
