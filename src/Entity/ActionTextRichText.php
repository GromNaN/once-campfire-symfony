<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\ActionTextRichTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The rich text body of a record, stored by ActionText in Rails.
 *
 * The table is polymorphic: record_type holds the Rails model name and
 * record_id the identifier. Messages use record_type "Message" and name "body".
 * The mapping has no association because the target varies.
 */
#[ORM\Entity(repositoryClass: ActionTextRichTextRepository::class)]
#[ORM\Table(name: 'action_text_rich_texts')]
#[ORM\UniqueConstraint(name: 'index_action_text_rich_texts_uniqueness', columns: ['record_type', 'record_id', 'name'])]
class ActionTextRichText implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private string $recordType = '';

    #[ORM\Column(type: Types::BIGINT)]
    private int $recordId = 0;

    #[ORM\Column]
    private string $name = 'body';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

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

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }
}
