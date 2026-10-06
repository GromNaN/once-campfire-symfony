<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Entity\Enum\MembershipInvolvement;
use App\Repository\RoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A chat room, mapped to the Rails single table inheritance hierarchy.
 *
 * The discriminator values are the fully qualified Rails class names, so the
 * same rows keep the same meaning in both applications.
 */
#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'rooms')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type')]
#[ORM\DiscriminatorMap([
    'Rooms::Open' => OpenRoom::class,
    'Rooms::Closed' => ClosedRoom::class,
    'Rooms::Direct' => DirectRoom::class,
])]
abstract class Room implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    // messages.room_id and memberships.room_id are "integer" columns in the
    // Rails schema, so the identifier they target is declared as an integer.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    #[ORM\Column(nullable: true)]
    protected ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: false)]
    protected ?User $creator = null;

    /**
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'room', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $memberships;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'room', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $messages;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

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
     * @return Collection<int, Membership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function isOpen(): bool
    {
        return $this instanceof OpenRoom;
    }

    public function isClosed(): bool
    {
        return $this instanceof ClosedRoom;
    }

    public function isDirect(): bool
    {
        return $this instanceof DirectRoom;
    }

    /**
     * Direct rooms notify on every message, other rooms only on mentions.
     */
    public function getDefaultInvolvement(): MembershipInvolvement
    {
        return MembershipInvolvement::Mentions;
    }

    public function addMember(User $user, ?MembershipInvolvement $involvement = null): Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()?->getId() === $user->getId()) {
                return $membership;
            }
        }

        $membership = new Membership();
        $membership->setRoom($this);
        $membership->setUser($user);
        $membership->setInvolvement($involvement ?? $this->getDefaultInvolvement());

        $this->memberships->add($membership);

        return $membership;
    }

    /**
     * @return list<User>
     */
    public function getMembers(): array
    {
        $members = [];

        foreach ($this->memberships as $membership) {
            $user = $membership->getUser();

            if (null !== $user) {
                $members[] = $user;
            }
        }

        return $members;
    }
}
