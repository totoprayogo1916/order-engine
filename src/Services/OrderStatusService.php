<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Services;

use DateTimeImmutable;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\OrderEngine\Contracts\OrderHistoryRepositoryInterface;
use Ttpryg\OrderEngine\Contracts\OrderRepositoryInterface;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Entities\OrderHistory;
use Ttpryg\OrderEngine\Enums\OrderHistoryAction;
use Ttpryg\OrderEngine\Enums\OrderStatus;
use Ttpryg\OrderEngine\Events\OrderCancelledEvent;
use Ttpryg\OrderEngine\Events\OrderCompletedEvent;
use Ttpryg\OrderEngine\Events\OrderConfirmedEvent;
use Ttpryg\OrderEngine\Events\OrderProcessingEvent;
use Ttpryg\OrderEngine\Events\OrderRefundedEvent;
use Ttpryg\OrderEngine\Events\OrderStatusChangedEvent;
use Ttpryg\OrderEngine\Exceptions\InvalidStatusTransitionException;
use Ttpryg\OrderEngine\Exceptions\OrderNotFoundException;
use Ttpryg\OrderEngine\Services\Concerns\ExecutesTransactions;

class OrderStatusService
{
    use ExecutesTransactions;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly OrderHistoryRepositoryInterface $historyRepo,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo;
    }

    public function changeStatus(
        Order $order,
        OrderStatus $targetStatus,
        ?string $actorId = null,
        ?string $note = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): Order {
        if ($order->status === $targetStatus) {
            return $order;
        }

        if (! $order->status->canTransitionTo($targetStatus)) {
            throw InvalidStatusTransitionException::create($order->status, $targetStatus);
        }

        $fromStatus = $order->status;
        $order->status = $targetStatus;
        $order->updatedAt = new DateTimeImmutable;

        $history = new OrderHistory(
            id: 'ord-hist-'.bin2hex(random_bytes(8)),
            orderId: $order->id,
            action: OrderHistoryAction::STATUS_CHANGED,
            fromStatus: $fromStatus,
            toStatus: $targetStatus,
            referenceType: $referenceType,
            referenceId: $referenceId,
            note: $note,
            actorId: $actorId
        );

        // Update status order dan pencatatan audit trail harus atomik:
        // jika salah satu gagal, keduanya dibatalkan.
        $this->executeInTransaction(function () use ($order, $history): void {
            $this->orderRepo->save($order);
            $this->historyRepo->save($history);
        });

        if ($this->eventDispatcher instanceof \Psr\EventDispatcher\EventDispatcherInterface) {
            $this->eventDispatcher->dispatch(new OrderStatusChangedEvent($order, $fromStatus, $targetStatus));

            match ($targetStatus) {
                OrderStatus::CONFIRMED => $this->eventDispatcher->dispatch(new OrderConfirmedEvent($order)),
                OrderStatus::PROCESSING => $this->eventDispatcher->dispatch(new OrderProcessingEvent($order)),
                OrderStatus::COMPLETED => $this->eventDispatcher->dispatch(new OrderCompletedEvent($order)),
                OrderStatus::CANCELLED => $this->eventDispatcher->dispatch(new OrderCancelledEvent($order, $note)),
                OrderStatus::REFUNDED => $this->eventDispatcher->dispatch(new OrderRefundedEvent($order, $note)),
                default => null,
            };
        }

        return $order;
    }

    public function confirm(string $orderId, ?string $actorId = null, ?string $note = null): Order
    {
        $order = $this->getExistingOrder($orderId);

        return $this->changeStatus($order, OrderStatus::CONFIRMED, $actorId, $note);
    }

    public function process(string $orderId, ?string $actorId = null, ?string $note = null): Order
    {
        $order = $this->getExistingOrder($orderId);

        return $this->changeStatus($order, OrderStatus::PROCESSING, $actorId, $note);
    }

    public function complete(string $orderId, ?string $actorId = null, ?string $note = null): Order
    {
        $order = $this->getExistingOrder($orderId);

        return $this->changeStatus($order, OrderStatus::COMPLETED, $actorId, $note);
    }

    public function cancel(string $orderId, ?string $actorId = null, ?string $reason = null): Order
    {
        $order = $this->getExistingOrder($orderId);

        return $this->changeStatus($order, OrderStatus::CANCELLED, $actorId, $reason);
    }

    public function refund(string $orderId, ?string $actorId = null, ?string $reason = null): Order
    {
        $order = $this->getExistingOrder($orderId);

        return $this->changeStatus($order, OrderStatus::REFUNDED, $actorId, $reason);
    }

    private function getExistingOrder(string $orderId): Order
    {
        $order = $this->orderRepo->findById($orderId);
        if (! $order instanceof \Ttpryg\OrderEngine\Entities\Order) {
            throw OrderNotFoundException::forId($orderId);
        }

        return $order;
    }
}
