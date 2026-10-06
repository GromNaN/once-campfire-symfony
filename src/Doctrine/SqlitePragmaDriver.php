<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * A SQLite driver that applies the application pragmas when it connects.
 *
 * Write ahead logging lets readers work while a writer holds the lock, and the
 * busy timeout makes concurrent writers wait instead of failing immediately.
 * Foreign keys are enforced, which SQLite disables by default. DBAL opens the
 * connection lazily, so the pragmas run once per connection and only for
 * connections that are used. The driver name in the connection parameters is
 * what tells SQLite apart from every other database.
 */
final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $connection = parent::connect($params);

        if (!$this->isSqlite($params)) {
            return $connection;
        }

        // journal_mode answers with the mode it settled on, so it is read as a
        // query. The others answer with nothing and are run as statements.
        $result = $connection->query('PRAGMA journal_mode = WAL');
        $result->free();

        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA synchronous = NORMAL');

        return $connection;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isSqlite(array $params): bool
    {
        $driver = $params['driver'] ?? $params['driverClass'] ?? null;

        return \is_string($driver) && false !== stripos($driver, 'sqlite');
    }
}
