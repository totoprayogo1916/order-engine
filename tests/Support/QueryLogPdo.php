<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\Tests\Support;

use PDO;
use PDOStatement;

/**
 * PDO wrapper yang mencatat setiap statement yang di-prepare, untuk menguji
 * bahwa repository tidak lagi melakukan SELECT sebelum INSERT/UPDATE.
 */
final class QueryLogPdo extends PDO
{
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;

        return parent::prepare($query, $options);
    }

    public function resetQueryLog(): void
    {
        $this->queries = [];
    }
}
