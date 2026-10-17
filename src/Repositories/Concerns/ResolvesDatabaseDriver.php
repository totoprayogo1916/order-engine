<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Repositories\Concerns;

use PDO;
use RuntimeException;

/**
 * Resolusi dan validasi driver PDO yang mendukung query upsert.
 *
 * - mysql  (MySQL / MariaDB): `INSERT ... ON DUPLICATE KEY UPDATE`
 * - sqlite / pgsql: `INSERT ... ON CONFLICT (...) DO UPDATE`
 */
trait ResolvesDatabaseDriver
{
    protected string $driver;

    protected function resolveDriver(PDO $pdo): string
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (! in_array($driver, ['mysql', 'sqlite', 'pgsql'], true)) {
            throw new RuntimeException("Unsupported PDO driver [{$driver}]. Supported drivers: mysql, sqlite, pgsql.");
        }

        return $driver;
    }
}
