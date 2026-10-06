<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Covers the timestamps the application writes.
 *
 * Rails reads these columns, so they keep the format ActiveRecord writes:
 * microseconds, in UTC, with no timezone marker.
 */
final class TimestampsTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    public function testPersistingAnEntityFillsBothTimestamps(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-01-02 03:04:05.678901', new \DateTimeZone('UTC')));

        $user = $this->createUser('Bob');
        $row = $this->timestampsOf('users', $user->getId());

        self::assertSame('2026-01-02 03:04:05.678901', $row['created_at']);
        self::assertSame('2026-01-02 03:04:05.678901', $row['updated_at']);
    }

    public function testUpdatingAnEntityRefreshesUpdatedAtAndKeepsCreatedAt(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-01-02 03:04:05.678901', new \DateTimeZone('UTC')));
        $user = $this->createUser('Bob');

        self::mockTime(new \DateTimeImmutable('2026-02-03 04:05:06.789012', new \DateTimeZone('UTC')));
        $user->setName('Robert');
        $this->entityManager()->flush();

        $row = $this->timestampsOf('users', $user->getId());

        self::assertSame('2026-01-02 03:04:05.678901', $row['created_at']);
        self::assertSame('2026-02-03 04:05:06.789012', $row['updated_at']);
    }

    public function testAnImportKeepsTheCreatedAtItCarries(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-01-02 03:04:05.678901', new \DateTimeZone('UTC')));

        $user = $this->createUser('Bob', new \DateTimeImmutable('2020-05-06 07:08:09.101112', new \DateTimeZone('UTC')));

        self::assertSame('2020-05-06 07:08:09.101112', $this->timestampsOf('users', $user->getId())['created_at']);
    }

    private function createUser(string $name, ?\DateTimeImmutable $createdAt = null): User
    {
        $user = new User();
        $user->setName($name);

        if (null !== $createdAt) {
            $user->setCreatedAt($createdAt);
        }

        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    /**
     * Reads the columns as they are stored, without going through Doctrine.
     *
     * @return array{created_at: string, updated_at: string}
     */
    private function timestampsOf(string $table, ?int $id): array
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT created_at, updated_at FROM "%s" WHERE id = ?', $table),
            [$id],
        );

        self::assertIsArray($row);

        return ['created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']];
    }
}
