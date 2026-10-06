<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\RailsDateTimeType;
use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Entity\Enum\MembershipInvolvement;
use App\Repository\MembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a user to a room and carries the notification preference.
 *
 * A membership is "connected" for sixty seconds after connected_at, and
 * connections counts the concurrent connections of the user in that room.
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'memberships')]
#[ORM\UniqueConstraint(name: 'index_memberships_on_room_id_and_user_id', columns: ['room_id', 'user_id'])]
#[ORM\Index(name: 'index_memberships_on_room_id_and_created_at', columns: ['room_id', 'created_at'])]
#[ORM\Index(name: 'index_memberships_on_room_id', columns: ['room_id'])]
#[ORM\Index(name: 'index_memberships_on_user_id', columns: ['user_id'])]
class Membership implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const CONNECTION_TTL = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false)]
    private ?Room $room = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(enumType: MembershipInvolvement::class, nullable: true, options: ['default' => 'mentions'])]
    private ?MembershipInvolvement $involvement = MembershipInvolvement::Mentions;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $connections = 0;

    #[ORM\Column(type: RailsDateTimeType::NAME, nullable: true)]
    private ?\DateTimeImmutable $connectedAt = null;

    #[ORM\Column(type: RailsDateTimeType::NAME, nullable: true)]
    private ?\DateTimeImmutable $unreadAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getInvolvement(): MembershipInvolvement
    {
        return $this->involvement ?? MembershipInvolvement::Mentions;
    }

    public function setInvolvement(?MembershipInvolvement $involvement): static
    {
        $this->involvement = $involvement;

        return $this;
    }

    public function getConnections(): int
    {
        return $this->connections;
    }

    public function setConnections(int $connections): static
    {
        $this->connections = $connections;

        return $this;
    }

    public function getConnectedAt(): ?\DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function setConnectedAt(?\DateTimeImmutable $connectedAt): static
    {
        $this->connectedAt = $connectedAt;

        return $this;
    }

    public function getUnreadAt(): ?\DateTimeImmutable
    {
        return $this->unreadAt;
    }

    public function setUnreadAt(?\DateTimeImmutable $unreadAt): static
    {
        $this->unreadAt = $unreadAt;

        return $this;
    }

    public function isVisible(): bool
    {
        return MembershipInvolvement::Invisible !== $this->getInvolvement();
    }

    public function isUnread(): bool
    {
        return null !== $this->unreadAt;
    }

    public function read(): static
    {
        return $this->setUnreadAt(null);
    }

    public function isConnected(): bool
    {
        return null !== $this->connectedAt && $this->connectedAt >= self::connectedThreshold();
    }

    /**
     * Marks the membership as connected and counts the new connection.
     */
    public function present(): void
    {
        $this->connections = $this->isConnected() ? $this->connections + 1 : 1;
        $this->connectedAt = self::now();
        $this->unreadAt = null;
    }

    /**
     * Keeps an existing connection alive without counting a new one.
     */
    public function refreshConnection(): void
    {
        if (!$this->isConnected()) {
            ++$this->connections;
        }

        $this->connectedAt = self::now();
    }

    public function disconnect(): void
    {
        if ($this->isConnected()) {
            $this->connections = max(0, $this->connections - 1);
        } else {
            $this->connections = 0;
        }

        if ($this->connections < 1) {
            $this->connections = 0;
            $this->connectedAt = null;
        }
    }

    private static function connectedThreshold(): \DateTimeImmutable
    {
        return self::now()->modify('-'.self::CONNECTION_TTL.' seconds');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
