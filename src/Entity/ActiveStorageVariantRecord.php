<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActiveStorageVariantRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marks a blob as the processed variant of another blob, so a thumbnail is only
 * generated once. The table has no timestamps, matching the original schema.
 */
#[ORM\Entity(repositoryClass: ActiveStorageVariantRecordRepository::class)]
#[ORM\Table(name: 'active_storage_variant_records')]
#[ORM\UniqueConstraint(name: 'index_active_storage_variant_records_uniqueness', columns: ['blob_id', 'variation_digest'])]
class ActiveStorageVariantRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ActiveStorageBlob::class)]
    #[ORM\JoinColumn(name: 'blob_id', referencedColumnName: 'id', nullable: false)]
    private ?ActiveStorageBlob $blob = null;

    #[ORM\Column]
    private string $variationDigest = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlob(): ?ActiveStorageBlob
    {
        return $this->blob;
    }

    public function setBlob(?ActiveStorageBlob $blob): static
    {
        $this->blob = $blob;

        return $this;
    }

    public function getVariationDigest(): string
    {
        return $this->variationDigest;
    }

    public function setVariationDigest(string $variationDigest): static
    {
        $this->variationDigest = $variationDigest;

        return $this;
    }
}
