<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAt;
use App\Entity\Concern\CreatedAtAware;
use App\Repository\ActiveStorageBlobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file uploaded through ActiveStorage.
 *
 * The bytes live on disk under storage/files/<key>. The metadata column holds
 * JSON, exactly like the original, and is exposed as an array.
 */
#[ORM\Entity(repositoryClass: ActiveStorageBlobRepository::class)]
#[ORM\Table(name: 'active_storage_blobs')]
#[ORM\UniqueConstraint(name: 'index_active_storage_blobs_on_key', columns: ['key'])]
class ActiveStorageBlob implements CreatedAtAware
{
    use CreatedAt;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'key')]
    private string $key = '';

    #[ORM\Column]
    private string $filename = '';

    #[ORM\Column(nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metadata = null;

    #[ORM\Column]
    private string $serviceName = 'local';

    #[ORM\Column(type: Types::BIGINT)]
    private int $byteSize = 0;

    #[ORM\Column(nullable: true)]
    private ?string $checksum = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function setServiceName(string $serviceName): static
    {
        $this->serviceName = $serviceName;

        return $this;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function setByteSize(int $byteSize): static
    {
        $this->byteSize = $byteSize;

        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): static
    {
        $this->checksum = $checksum;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        if (null === $this->metadata || '' === $this->metadata) {
            return [];
        }

        $decoded = json_decode($this->metadata, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = [] === $metadata ? null : json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->contentType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->contentType, 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with((string) $this->contentType, 'audio/');
    }
}
