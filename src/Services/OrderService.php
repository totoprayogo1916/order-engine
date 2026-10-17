<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Services;

use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\OrderEngine\Contracts\OrderAddressRepositoryInterface;
use Ttpryg\OrderEngine\Contracts\OrderHistoryRepositoryInterface;
use Ttpryg\OrderEngine\Contracts\OrderItemRepositoryInterface;
use Ttpryg\OrderEngine\Contracts\OrderRepositoryInterface;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Entities\OrderAddress;
use Ttpryg\OrderEngine\Entities\OrderHistory;
use Ttpryg\OrderEngine\Entities\OrderItem;
use Ttpryg\OrderEngine\Enums\OrderHistoryAction;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Events\OrderCreatedEvent;
use Ttpryg\OrderEngine\Exceptions\OrderNotFoundException;
use Ttpryg\OrderEngine\Services\Concerns\ExecutesTransactions;
use Ttpryg\OrderEngine\ValueObjects\Money;
use Ttpryg\OrderEngine\ValueObjects\OrderNumber;

class OrderService
{
    use ExecutesTransactions;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly OrderItemRepositoryInterface $itemRepo,
        private readonly OrderAddressRepositoryInterface $addressRepo,
        private readonly OrderHistoryRepositoryInterface $historyRepo,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo;
    }

    /**
     * @param  OrderItem[]  $items
     * @param  OrderAddress[]  $addresses
     */
    public function createOrder(
        string $id,
        string $customerType,
        string $customerId,
        array $items = [],
        array $addresses = [],
        ?string $orderNumber = null,
        string $currency = 'IDR',
        Money|int|float|string $discountTotal = 0,
        Money|int|float|string $taxTotal = 0,
        Money|int|float|string $shippingTotal = 0,
        ?string $note = null,
        ?string $actorId = null
    ): Order {
        $resolvedOrderNumber = $orderNumber ?? (string) OrderNumber::generate();

        $order = new Order(
            id: $id,
            orderNumber: $resolvedOrderNumber,
            customerType: $customerType,
            customerId: $customerId,
            status: OrderStatus::PENDING,
            currency: $currency,
            discountTotal: $discountTotal,
            taxTotal: $taxTotal,
            shippingTotal: $shippingTotal,
            note: $note
        );

        foreach ($items as $item) {
            $order->addItem($item);
        }
        foreach ($addresses as $address) {
            $order->addresses[] = $address;
        }

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::ORDER_CREATED,
            toStatus: OrderStatus::PENDING,
            note: $note,
            actorId: $actorId
        );

        $this->executeInTransaction(function () use ($order, $history): void {
            $this->orderRepo->save($order);
            foreach ($order->items as $item) {
                $this->itemRepo->save($item);
            }
            foreach ($order->addresses as $address) {
                $this->addressRepo->save($address);
            }
            $this->historyRepo->save($history);
        });

        if ($this->eventDispatcher instanceof \Psr\EventDispatcher\EventDispatcherInterface) {
            $this->eventDispatcher->dispatch(new OrderCreatedEvent($order));
        }

        return $order;
    }

    public function getOrder(string $id): ?Order
    {
        $order = $this->orderRepo->findById($id);
        if (! $order instanceof \Ttpryg\OrderEngine\Entities\Order) {
            return null;
        }

        $order->items = $this->itemRepo->findByOrderId($order->id);
        $order->addresses = $this->addressRepo->findByOrderId($order->id);
        $order->recalculateTotals();

        return $order;
    }

    public function getOrderByNumber(string $orderNumber): ?Order
    {
        $order = $this->orderRepo->findByOrderNumber($orderNumber);
        if (! $order instanceof \Ttpryg\OrderEngine\Entities\Order) {
            return null;
        }

        $order->items = $this->itemRepo->findByOrderId($order->id);
        $order->addresses = $this->addressRepo->findByOrderId($order->id);
        $order->recalculateTotals();

        return $order;
    }

    public function addItem(string $orderId, OrderItem $item, ?string $actorId = null): Order
    {
        $order = $this->getExistingOrder($orderId);
        $order->addItem($item);

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::ITEM_ADDED,
            note: "Added item '{$item->name}' (Qty: {$item->quantity})",
            actorId: $actorId
        );

        $this->executeInTransaction(function () use ($order, $item, $history): void {
            $this->itemRepo->save($item);
            $this->orderRepo->save($order);
            $this->historyRepo->save($history);
        });

        return $order;
    }

    public function removeItem(string $orderId, string $itemId, ?string $actorId = null): Order
    {
        $order = $this->getExistingOrder($orderId);
        $order->removeItem($itemId);

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::ITEM_REMOVED,
            note: "Removed item ID {$itemId}",
            actorId: $actorId
        );

        $this->executeInTransaction(function () use ($order, $itemId, $history): void {
            $this->itemRepo->delete($itemId);
            $this->orderRepo->save($order);
            $this->historyRepo->save($history);
        });

        return $order;
    }

    public function updateItemQuantity(string $orderId, string $itemId, int $quantity, ?string $actorId = null): Order
    {
        $order = $this->getExistingOrder($orderId);
        $order->updateItemQuantity($itemId, $quantity);

        $item = $this->itemRepo->findById($itemId);
        if ($item instanceof \Ttpryg\OrderEngine\Entities\OrderItem) {
            $item->quantity = max(1, $quantity);
            $item->calculateSubtotal();
        }

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::ITEM_QUANTITY_CHANGED,
            note: "Updated item ID {$itemId} quantity to {$quantity}",
            actorId: $actorId
        );

        $this->executeInTransaction(function () use ($order, $item, $history): void {
            if ($item instanceof \Ttpryg\OrderEngine\Entities\OrderItem) {
                $this->itemRepo->save($item);
            }
            $this->orderRepo->save($order);
            $this->historyRepo->save($history);
        });

        return $order;
    }

    public function addAddress(string $orderId, OrderAddress $address, ?string $actorId = null): Order
    {
        $order = $this->getExistingOrder($orderId);
        $order->addresses[] = $address;

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::ADDRESS_CHANGED,
            note: "Added address type '{$address->type->value}'",
            actorId: $actorId
        );

        $this->executeInTransaction(function () use ($address, $history): void {
            $this->addressRepo->save($address);
            $this->historyRepo->save($history);
        });

        return $order;
    }

    public function updateAddress(string $orderId, OrderAddress $address, ?string $actorId = null): Order
    {
        $order = $this->getExistingOrder($orderId);

        $updatedAddresses = [];
        foreach ($order->addresses as $existing) {
            if ($existing->id === $address->id || $existing->type === $address->type) {
                $updatedAddresses[] = $address;
            } else {
                $updatedAddresses[] = $existing;
            }
        }
        $order->addresses = $updatedAddresses;

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::ADDRESS_CHANGED,
            note: "Updated address type '{$address->type->value}'",
            actorId: $actorId
        );

        $this->executeInTransaction(function () use ($address, $history): void {
            $this->addressRepo->save($address);
            $this->historyRepo->save($history);
        });

        return $order;
    }

    private function getExistingOrder(string $orderId): Order
    {
        $order = $this->getOrder($orderId);
        if (! $order instanceof \Ttpryg\OrderEngine\Entities\Order) {
            throw OrderNotFoundException::forId($orderId);
        }

        return $order;
    }
}
