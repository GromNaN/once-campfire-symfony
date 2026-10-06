<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAt;
use App\Entity\Concern\CreatedAtAware;
use App\Repository\ActiveStorageAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a blob to the record that owns it.
 *
 * record_type holds the Rails model name, for example "User" for an avatar or
 * "Message" for a message attachment, and name holds the attachment name.
 */
#[ORM\Entity(repositoryClass: ActiveStorageAttachmentRepository::class)]
#[ORM\Table(name: 'active_storage_attachments')]
#[ORM\Index(name: 'index_active_storage_attachments_on_blob_id', columns: ['blob_id'])]
#[ORM\UniqueConstraint(name: 'index_active_storage_attachments_uniqueness', columns: ['record_type', 'record_id', 'name', 'blob_id'])]
class ActiveStorageAttachment implements CreatedAtAware
{
    use CreatedAt;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private string $recordType = '';

    #[ORM\Column(type: Types::BIGINT)]
    private int $recordId = 0;

    #[ORM\Column]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: ActiveStorageBlob::class)]
    #[ORM\JoinColumn(name: 'blob_id', referencedColumnName: 'id', nullable: false)]
    private ?ActiveStorageBlob $blob = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordType(): string
    {
        return $this->recordType;
    }

    public function setRecordType(string $recordType): static
    {
        $this->recordType = $recordType;

        return $this;
    }

    public function getRecordId(): int
    {
        return $this->recordId;
    }

    public function setRecordId(int $recordId): static
    {
        $this->recordId = $recordId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
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
}
