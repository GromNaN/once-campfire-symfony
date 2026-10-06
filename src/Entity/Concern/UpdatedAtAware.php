<?php

declare(strict_types=1);

namespace App\Entity\Concern;

/**
 * An entity whose table has an updated_at column.
 *
 * TimestampListener refreshes the column on every insert and update. The value
 * comes from the clock the listener is given, not from a lifecycle callback on
 * the entity, so a test can replace the clock and freeze time.
 */
interface UpdatedAtAware
{
    public function touchUpdatedAt(\DateTimeImmutable $updatedAt): void;
}
