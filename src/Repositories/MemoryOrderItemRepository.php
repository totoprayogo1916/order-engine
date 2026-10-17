<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories;

use Ttpryg\OrderEngine\Contracts\OrderItemRepositoryInterface;
use Ttpryg\OrderEngine\Entities\OrderItem;

class MemoryOrderItemRepository implements OrderItemRepositoryInterface
{
    /** @var array<string, OrderItem> */
    private array $items = [];

    public function save(OrderItem $item): void
    {
        $this->items[$item->id] = $item;
    }

    public function saveMany(array $items): void
    {
        foreach ($items as $item) {
            $this->save($item);
        }
    }

    public function findById(string $id): ?OrderItem
    {
        return $this->items[$id] ?? null;
    }

    public function findByOrderId(string $orderId): array
    {
        $result = [];
        foreach ($this->items as $item) {
            if ($item->orderId === $orderId) {
                $result[] = $item;
            }
        }

        return $result;
    }

    public function delete(string $id): bool
    {
        if (isset($this->items[$id])) {
            unset($this->items[$id]);

            return true;
        }

        return false;
    }

    public function deleteByOrderId(string $orderId): bool
    {
        $deleted = false;
        foreach ($this->items as $id => $item) {
            if ($item->orderId === $orderId) {
                unset($this->items[$id]);
                $deleted = true;
            }
        }

        return $deleted;
    }
}
