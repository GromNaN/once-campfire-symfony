<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\BoostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A short reaction added to a message, for example a single emoji.
 */
#[ORM\Entity(repositoryClass: BoostRepository::class)]
#[ORM\Table(name: 'boosts')]
#[ORM\Index(name: 'index_boosts_on_booster_id', columns: ['booster_id'])]
#[ORM\Index(name: 'index_boosts_on_message_id', columns: ['message_id'])]
class Boost implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $content = '';

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'boosts')]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false)]
    private ?Message $message = null;

    /**
     * The original schema declares no foreign key on booster_id, but the
     * association is still mapped so the booster can be loaded.
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'boosts')]
    #[ORM\JoinColumn(name: 'booster_id', referencedColumnName: 'id', nullable: false)]
    private ?User $booster = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content ?? '';

        return $this;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getBooster(): ?User
    {
        return $this->booster;
    }

    public function setBooster(?User $booster): static
    {
        $this->booster = $booster;

        return $this;
    }
}
