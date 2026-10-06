<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQLite settings the application relies on are applied by a driver
 * middleware, because DBAL 4 removed the connection events the old listener
 * used. Booting the kernel and reading the pragmas back is the way to prove
 * the middleware is registered and runs.
 */
final class SqlitePragmaMiddlewareTest extends KernelTestCase
{
    public function testTheSqlitePragmasAreAppliedToTheConnection(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine')->getConnection();

        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame('wal', strtolower((string) $connection->fetchOne('PRAGMA journal_mode')));
        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame(5000, (int) $connection->fetchOne('PRAGMA busy_timeout'));
    }
}
