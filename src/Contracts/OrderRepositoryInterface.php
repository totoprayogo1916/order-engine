<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Contracts;

use Ttpryg\OrderEngine\Entities\Order;
use Ttpryg\OrderEngine\ValueObjects\OrderFilter;
use Ttpryg\OrderEngine\ValueObjects\PaginatedResult;

interface OrderRepositoryInterface
{
    public function save(Order $order): void;

    public function findById(string $id): ?Order;

    public function findByOrderNumber(string $orderNumber): ?Order;

    /**
     * @return Order[]
     */
    public function findByCustomer(string $customerType, string $customerId): array;

    /**
     * Satu halaman order milik satu customer, diurutkan terbaru dahulu
     * (`created_at` DESC, lalu `id` DESC sebagai pemecah seri).
     *
     * `page` dan `perPage` dinormalisasi (page minimal 1, perPage 1–100).
     *
     * @return PaginatedResult<Order>
     */
    public function paginateByCustomer(
        string $customerType,
        string $customerId,
        int $page = 1,
        int $perPage = 20,
        ?OrderFilter $filter = null
    ): PaginatedResult;

    public function delete(string $id): bool;
}
