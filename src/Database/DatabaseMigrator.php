<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Database;

use PDO;

class DatabaseMigrator
{
    public function __construct(private readonly PDO $pdo) {}

    public function migrate(): void
    {
        // Deteksi sebelum exec: fresh install tidak punya tabel orders,
        // database existing (mis. production lama) sudah punya.
        $isFreshInstall = ! $this->tableExists('orders');

        $sql = file_get_contents(__DIR__.'/../../database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Unable to load order-engine schema.sql file.');
        }

        $this->pdo->exec($sql);

        $runner = new MigrationRunner($this->pdo);

        if ($isFreshInstall) {
            // Fresh install: schema.sql adalah source of truth dan sudah mencakup
            // semua definisi terkini → tandai semua migrasi sebagai baseline
            // agar tidak dieksekusi ulang.
            $runner->markAllAsMigrated();

            return;
        }

        // Database existing: jalankan migrasi inkremental yang belum diterapkan
        // (mis. ALTER TABLE untuk database yang dibuat sebelum kolom version ada).
        // Tabel data lama tidak disentuh oleh schema.sql (CREATE TABLE IF NOT EXISTS).
        $runner->migrate();
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Throwable) {
            return false;
        }

        return $stmt !== false;
    }
}
