<?php

declare(strict_types=1);

namespace App\Entity\Concern;

use App\Doctrine\Type\RailsDateTimeType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Adds the created_at column.
 *
 * The Rails schema gives created_at to every table but updated_at only to
 * some of them. ActiveStorage blobs and attachments have no updated_at, so
 * they use this trait alone while the other entities use Timestamps.
 *
 * The column uses the rails_datetime type so the stored text matches what
 * ActiveRecord writes, which keeps the same SQLite file readable by the
 * original application.
 */
trait CreatedAt
{
    #[ORM\Column(type: RailsDateTimeType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function initializeCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt ??= $createdAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Used when importing rows that already carry a timestamp.
     */
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
