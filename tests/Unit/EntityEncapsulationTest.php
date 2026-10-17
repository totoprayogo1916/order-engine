<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\ValueObjects\Money;

/**
 * Membuktikan properti kalkulasi ter-enkapsulasi (privat dengan getter) dan
 * invariant kuantitas dijaga lewat guard assertion — bukan sekadar konvensi.
 */
class EntityEncapsulationTest extends TestCase
{
    /** @var list<string> */
    private const ORDER_CALCULATION_PROPERTIES = ['subtotal', 'discountTotal', 'taxTotal', 'shippingTotal', 'grandTotal'];

    /** @var list<string> */
    private const ITEM_CALCULATION_PROPERTIES = ['quantity', 'unitPrice', 'discountTotal', 'taxTotal', 'subtotal'];

    public function test_order_calculation_properties_are_not_publicly_mutable(): void
    {
        $reflection = new ReflectionClass(Order::class);

        foreach (self::ORDER_CALCULATION_PROPERTIES as $property) {
            $this->assertFalse(
                $reflection->getProperty($property)->isPublic(),
                "Order::\${$property} must not be public — totals are only produced by recalculateTotals()."
            );
        }
    }

    public function test_item_calculation_properties_are_not_publicly_mutable(): void
    {
        $reflection = new ReflectionClass(OrderItem::class);

        foreach (self::ITEM_CALCULATION_PROPERTIES as $property) {
            $this->assertFalse(
                $reflection->getProperty($property)->isPublic(),
                "OrderItem::\${$property} must not be public — mutate through official methods only."
            );
        }
    }

    public function test_item_rejects_zero_or_negative_quantity_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be at least 1');

        new OrderItem(id: 'item-1', orderId: 'ord-1', productId: 'prod-1', quantity: 0);
    }

    public function test_item_rejects_negative_quantity_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OrderItem(id: 'item-2', orderId: 'ord-1', productId: 'prod-1', quantity: -5);
    }

    public function test_update_quantity_rejects_zero(): void
    {
        $item = $this->makeItem(quantity: 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be at least 1');

        $item->updateQuantity(0);
    }

    public function test_update_quantity_rejects_negative_and_keeps_previous_state(): void
    {
        $item = $this->makeItem(quantity: 2);

        try {
            $item->updateQuantity(-1);
            $this->fail('Negative quantity harusnya ditolak.');
        } catch (InvalidArgumentException) {
            // Diharapkan.
        }

        // Guard gagal di tengah → kuantitas & subtotal tidak berubah (tidak setengah jadi).
        $this->assertSame(2, $item->quantity());
        $this->assertSame('20000.00', $item->subtotal()->toDecimal());
    }

    public function test_order_update_item_quantity_propagates_guard(): void
    {
        $order = $this->makeOrderWithItem($item);

        try {
            $order->updateItemQuantity($item->id, 0);
            $this->fail('Kuantitas 0 harusnya ditolak guard invariant.');
        } catch (InvalidArgumentException) {
            // Diharapkan.
        }

        $this->assertSame(1, $item->quantity());
        $this->assertSame('10000.00', $item->subtotal()->toDecimal());
    }

    public function test_official_quantity_path_recomputes_subtotal_and_order_totals(): void
    {
        $order = $this->makeOrderWithItem($item);

        $order->updateItemQuantity($item->id, 3);

        $this->assertSame(3, $item->quantity());
        $this->assertSame('30000.00', $item->subtotal()->toDecimal());
        $this->assertSame('30000.00', $order->subtotal()->toDecimal());
        $this->assertSame('30000.00', $order->grandTotal()->toDecimal());
    }

    public function test_constructor_normalizes_money_and_exposes_immutable_getters(): void
    {
        $item = new OrderItem(
            id: 'item-money',
            orderId: 'ord-money',
            productId: 'prod-money',
            quantity: 2,
            unitPrice: 10000
        );

        $this->assertSame('10000.00', $item->unitPrice()->toDecimal());
        $this->assertSame('0.00', $item->discountTotal()->toDecimal());
        $this->assertSame('0.00', $item->taxTotal()->toDecimal());
        $this->assertSame('20000.00', $item->subtotal()->toDecimal());

        // Getter mengembalikan instance Money yang sama (immutable) — bukan salinan yang bisa divergensi.
        $this->assertSame($item->unitPrice(), $item->unitPrice());
    }

    private function makeItem(int $quantity): OrderItem
    {
        return new OrderItem(
            id: 'item-guard',
            orderId: 'ord-guard',
            productId: 'prod-guard',
            quantity: $quantity,
            unitPrice: 10000
        );
    }

    private function makeOrderWithItem(?OrderItem &$item): Order
    {
        $item = new OrderItem(
            id: 'item-order-guard',
            orderId: 'ord-order-guard',
            productId: 'prod-order-guard',
            quantity: 1,
            unitPrice: 10000
        );

        $order = new Order(
            id: 'ord-order-guard',
            orderNumber: 'ORD-GUARD-001',
            customerType: 'user',
            customerId: 'user-guard'
        );
        $order->addItem($item);

        return $order;
    }
}
