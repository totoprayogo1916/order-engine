<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories;

use Ttpryg\OrderEngine\Contracts\OrderRepositoryInterface;
use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\Repositories\Concerns\NormalizesPagination;
use Ttpryg\OrderEngine\ValueObjects\OrderFilter;
use Ttpryg\OrderEngine\ValueObjects\PaginatedResult;

class MemoryOrderRepository implements OrderRepositoryInterface
{
    use NormalizesPagination;

    /** @var array<string, Order> */
    private array $orders = [];

    public function save(Order $order): void
    {
        if (isset($this->orders[$order->id])) {
            // Menjaga paritas perilaku optimistic locking dengan driver PDO:
            // setiap update menyimpan versi baru.
            $order->version++;
        }

        $this->orders[$order->id] = $order;
    }

    public function findById(string $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->orderNumber === $orderNumber) {
                return $order;
            }
        }

        return null;
    }

    public function findByCustomer(string $customerType, string $customerId): array
    {
        $result = [];
        foreach ($this->orders as $order) {
            if ($order->customerType === $customerType && $order->customerId === $customerId) {
                $result[] = $order;
            }
        }

        return $result;
    }

    public function paginateByCustomer(
        string $customerType,
        string $customerId,
        int $page = 1,
        int $perPage = 20,
        ?OrderFilter $filter = null
    ): PaginatedResult {
        [$page, $perPage] = $this->normalizePagination($page, $perPage);
        $filter ??= new OrderFilter;

        $matched = array_values(array_filter(
            $this->orders,
            fn (Order $order) => $order->customerType === $customerType
                && $order->customerId === $customerId
                && $this->matchesFilter($order, $filter)
        ));

        // Paritas dengan driver PDO: created_at DESC, lalu id DESC sebagai pemecah seri.
        usort($matched, function (Order $a, Order $b): int {
            $byCreatedAt = $b->createdAt <=> $a->createdAt;

            return $byCreatedAt !== 0 ? $byCreatedAt : strcmp($b->id, $a->id);
        });

        $total = count($matched);

        return new PaginatedResult(
            items: array_slice($matched, ($page - 1) * $perPage, $perPage),
            page: $page,
            perPage: $perPage,
            total: $total,
            totalPages: $total > 0 ? (int) ceil($total / $perPage) : 0
        );
    }

    private function matchesFilter(Order $order, OrderFilter $filter): bool
    {
        if ($filter->status instanceof \Ttpryg\OrderEngine\Enums\OrderStatus && $order->status !== $filter->status) {
            return false;
        }

        if ($filter->paymentStatus instanceof \Ttpryg\OrderEngine\Enums\PaymentStatus
            && ! in_array($order->status, $filter->paymentStatus->orderStatuses(), true)) {
            return false;
        }

        if ($filter->createdFrom instanceof \DateTimeImmutable && $order->createdAt < $filter->createdFrom) {
            return false;
        }

        return ! $filter->createdTo instanceof \DateTimeImmutable || $order->createdAt <= $filter->createdTo;
    }

    public function delete(string $id): bool
    {
        if (isset($this->orders[$id])) {
            unset($this->orders[$id]);

            return true;
        }

        return false;
    }
}
