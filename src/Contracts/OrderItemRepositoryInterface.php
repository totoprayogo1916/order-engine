<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Contracts;

use Ttpryg\OrderEngine\Entities\OrderItem;

interface OrderItemRepositoryInterface
{
    public function save(OrderItem $item): void;

    /**
     * Menyimpan banyak item dalam satu statement (bulk upsert).
     *
     * @param  OrderItem[]  $items
     */
    public function saveMany(array $items): void;

    public function findById(string $id): ?OrderItem;

    /**
     * @return OrderItem[]
     */
    public function findByOrderId(string $orderId): array;

    public function delete(string $id): bool;

    public function deleteByOrderId(string $orderId): bool;
}
