<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\ValueObjects;

/**
 * Hasil satu halaman kueri beserta metadata paginasi.
 *
 * @template T
 */
final class PaginatedResult
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages
    ) {}

    public function hasMore(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
