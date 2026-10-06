<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message posted in a room.
 *
 * The rich text body lives in the polymorphic action_text_rich_texts table and
 * is reached through MessageRepository, not through a Doctrine association,
 * because the table has no discriminator aware mapping.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'index_messages_on_creator_id', columns: ['creator_id'])]
#[ORM\Index(name: 'index_messages_on_room_id_and_created_at', columns: ['room_id', 'created_at'])]
#[ORM\Index(name: 'index_messages_on_room_id', columns: ['room_id'])]
#[ORM\HasLifecycleCallbacks]
class Message implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const PAGE_SIZE = 40;

    // boosts.message_id is an "integer" column in the Rails schema, so the
    // identifier it targets is declared as an integer.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifier chosen by the client so an optimistic message can be replaced
     * in place. It is also the public DOM identifier of the message.
     */
    #[ORM\Column]
    private string $clientMessageId = '';

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false)]
    private ?Room $room = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: false)]
    private ?User $creator = null;

    /**
     * @var Collection<int, Boost>
     */
    #[ORM\OneToMany(targetEntity: Boost::class, mappedBy: 'message', cascade: ['remove'], orphanRemoval: true)]
    private Collection $boosts;

    public function __construct()
    {
        $this->boosts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientMessageId(): string
    {
        return $this->clientMessageId;
    }

    public function setClientMessageId(string $clientMessageId): static
    {
        $this->clientMessageId = $clientMessageId;

        return $this;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): static
    {
        $this->room = $room;

        return $this;
    }

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    /**
     * @return Collection<int, Boost>
     */
    public function getBoosts(): Collection
    {
        return $this->boosts;
    }

    /**
     * The DOM identifier of a message is its client identifier, not its row id.
     */
    public function getKey(): string
    {
        return $this->clientMessageId;
    }

    #[ORM\PrePersist]
    public function assignClientMessageId(): void
    {
        if ('' === $this->clientMessageId) {
            $this->clientMessageId = self::generateClientMessageId();
        }
    }

    public static function generateClientMessageId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
