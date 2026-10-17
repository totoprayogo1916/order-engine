<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Services\Concerns;

use PDO;
use Throwable;

/**
 * Menjalankan callback dalam satu transaksi database atomik.
 *
 * Properti `$pdo` dideklarasikan oleh trait ini; null berarti callback dijalankan
 * tanpa transaksi (mis. saat memakai repository in-memory). Jika sebuah transaksi
 * sudah berjalan di koneksi yang sama, callback dimasukkan ke dalam transaksi
 * induk dan komit/rollback dikendalikan oleh scope luar.
 */
trait ExecutesTransactions
{
    protected ?PDO $pdo = null;

    /**
     * @param  callable(): void  $callback
     */
    private function executeInTransaction(callable $callback): void
    {
        if (! $this->pdo instanceof PDO || $this->pdo->inTransaction()) {
            $callback();

            return;
        }

        $this->pdo->beginTransaction();
        try {
            $callback();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }
}
