<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Applies the SQLite settings the application relies on.
 *
 * Write ahead logging lets readers work while a writer holds the lock, and the
 * busy timeout makes concurrent writers wait instead of failing immediately.
 * Foreign keys are enforced, which SQLite disables by default.
 */
final class SqlitePragmaListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function postConnect(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $this->connection->executeStatement('PRAGMA journal_mode = WAL');
        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('PRAGMA synchronous = NORMAL');
    }
}
