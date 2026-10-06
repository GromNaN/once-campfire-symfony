<?php

declare(strict_types=1);

namespace App\Entity\Concern;

use App\Doctrine\Type\RailsDateTimeType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Adds the created_at and updated_at columns every Rails table carries.
 *
 * Both use the rails_datetime type so the stored text matches what
 * ActiveRecord writes, which keeps the same SQLite file readable by the
 * original application.
 */
trait Timestamps
{
    use CreatedAt;

    #[ORM\Column(type: RailsDateTimeType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function touchUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Marks the record as changed, which moves updated_at on the next flush.
     *
     * A record whose only change is written to another table, such as the body
     * of a message, would otherwise keep the timestamp it had, and the clients
     * that follow what changed in a room read that timestamp.
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Used when importing rows that already carry a timestamp.
     */
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
