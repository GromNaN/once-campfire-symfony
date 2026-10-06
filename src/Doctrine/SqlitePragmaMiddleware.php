<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Applies the SQLite settings the application relies on to every connection.
 *
 * DBAL 4 removed the connection events the application used to listen to, so
 * the settings are applied through a driver middleware instead. The middleware
 * is registered for every connection and hands the driver back wrapped; the
 * wrapped driver decides from the connection parameters whether the database
 * is SQLite, so a connection that is not SQLite is left untouched. The check
 * cannot live here because the logging and profiling middlewares of the bundle
 * wrap the driver first, which hides the driver class.
 */
#[AsMiddleware]
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmaDriver($driver);
    }
}
