<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories;

use Ttpryg\OrderEngine\Contracts\OrderRepositoryInterface;
use Ttpryg\OrderEngine\Entities\Order;

class MemoryOrderRepository implements OrderRepositoryInterface
{
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

    public function delete(string $id): bool
    {
        if (isset($this->orders[$id])) {
            unset($this->orders[$id]);

            return true;
        }

        return false;
    }
}
