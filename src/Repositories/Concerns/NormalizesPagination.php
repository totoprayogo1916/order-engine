<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories\Concerns;

/**
 * Menormalisasi parameter paginasi agar perilaku identik di semua driver:
 * page minimal 1, perPage dibatasi 1–100.
 */
trait NormalizesPagination
{
    /**
     * @return array{int, int} [page, perPage] yang sudah dinormalisasi
     */
    private function normalizePagination(int $page, int $perPage): array
    {
        return [max(1, $page), min(100, max(1, $perPage))];
    }
}
