<?php

declare(strict_types=1);

namespace App\Entity\Concern;

/**
 * An entity whose table has a created_at column.
 *
 * TimestampListener fills the column when the entity is persisted. The value
 * comes from the clock the listener is given, not from a lifecycle callback on
 * the entity, so a test can replace the clock and freeze time.
 */
interface CreatedAtAware
{
    /**
     * Fills created_at unless the entity already carries one, which is what an
     * import of existing rows does.
     */
    public function initializeCreatedAt(\DateTimeImmutable $createdAt): void;
}
