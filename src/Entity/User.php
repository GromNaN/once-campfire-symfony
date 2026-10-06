<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person or a bot.
 *
 * Password storage follows the original application: a bcrypt digest in
 * password_digest, with no validation on the plain password because accounts
 * are created through invitation codes and the first run form.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'index_users_on_email_address', columns: ['email_address'])]
#[ORM\UniqueConstraint(name: 'index_users_on_bot_token', columns: ['bot_token'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const AVATAR_SIZE = 512;
    public const TRANSFER_LINK_LIFETIME = 14400;

    // Every foreign key that points here is an "integer" column in the Rails
    // schema, and the ORM derives the type of a foreign key from the type of
    // the identifier it targets, so the identifier is declared as an integer.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column]
    private string $name = '';

    #[ORM\Column(nullable: true)]
    private ?string $emailAddress = null;

    #[ORM\Column(nullable: true)]
    private ?string $passwordDigest = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(nullable: true)]
    private ?string $botToken = null;

    #[ORM\Column(type: Types::INTEGER, enumType: UserRole::class, options: ['default' => 0])]
    private UserRole $role = UserRole::Member;

    #[ORM\Column(type: Types::INTEGER, enumType: UserStatus::class, options: ['default' => 0])]
    private UserStatus $status = UserStatus::Active;

    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $memberships;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'creator', cascade: ['remove'], orphanRemoval: true)]
    private Collection $messages;

    #[ORM\OneToMany(targetEntity: Boost::class, mappedBy: 'booster', cascade: ['remove'], orphanRemoval: true)]
    private Collection $boosts;

    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $sessions;

    #[ORM\OneToMany(targetEntity: Ban::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $bans;

    #[ORM\OneToMany(targetEntity: PushSubscription::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $pushSubscriptions;

    #[ORM\OneToMany(targetEntity: Search::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $searches;

    /**
     * A bot has at most one webhook. The Rails schema puts no unique index on
     * webhooks.user_id, so the mapping is a collection of at most one.
     *
     * @var Collection<int, Webhook>
     */
    #[ORM\OneToMany(targetEntity: Webhook::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $webhooks;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
        $this->messages = new ArrayCollection();
        $this->boosts = new ArrayCollection();
        $this->sessions = new ArrayCollection();
        $this->bans = new ArrayCollection();
        $this->pushSubscriptions = new ArrayCollection();
        $this->searches = new ArrayCollection();
        $this->webhooks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(?string $emailAddress): static
    {
        $this->emailAddress = $emailAddress;

        return $this;
    }

    public function getPasswordDigest(): ?string
    {
        return $this->passwordDigest;
    }

    public function setPasswordDigest(?string $passwordDigest): static
    {
        $this->passwordDigest = $passwordDigest;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getBotToken(): ?string
    {
        return $this->botToken;
    }

    public function setBotToken(?string $botToken): static
    {
        $this->botToken = $botToken;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isAdministrator(): bool
    {
        return $this->role->isAdministrator();
    }

    public function isBot(): bool
    {
        return $this->role->isBot();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isDeactivated(): bool
    {
        return $this->status->isDeactivated();
    }

    public function isBanned(): bool
    {
        return $this->status->isBanned();
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

    /**
     * @return Collection<int, Boost>
     */
    public function getBoosts(): Collection
    {
        return $this->boosts;
    }

    /**
     * @return Collection<int, Session>
     */
    public function getSessions(): Collection
    {
        return $this->sessions;
    }

    /**
     * @return Collection<int, Ban>
     */
    public function getBans(): Collection
    {
        return $this->bans;
    }

    /**
     * @return Collection<int, PushSubscription>
     */
    public function getPushSubscriptions(): Collection
    {
        return $this->pushSubscriptions;
    }

    /**
     * @return Collection<int, Search>
     */
    public function getSearches(): Collection
    {
        return $this->searches;
    }

    /**
     * @return Collection<int, Webhook>
     */
    public function getWebhooks(): Collection
    {
        return $this->webhooks;
    }

    /**
     * Attaches a webhook to the bot, on both sides of the association.
     *
     * The collection is written as well as the owning side, so that a bot just
     * given an endpoint is told about a message before it is read back from
     * the database.
     */
    public function addWebhook(Webhook $webhook): Webhook
    {
        $webhook->setUser($this);
        $this->webhooks->add($webhook);

        return $webhook;
    }

    /**
     * The webhook of a bot, or null when it has none.
     */
    public function getWebhook(): ?Webhook
    {
        return $this->webhooks->first() ?: null;
    }

    /**
     * First letter of every word, used for the generated avatar.
     */
    public function getInitials(): string
    {
        preg_match_all('/\b\w/u', $this->name, $matches);

        return implode('', $matches[0]);
    }

    /**
     * Name and bio joined by an en dash, used as the page title.
     */
    public function getTitle(): string
    {
        return implode(" \u{2013} ", array_filter([$this->name, $this->bio], static fn (?string $value) => null !== $value && '' !== trim($value)));
    }

    /**
     * A user may administer a record when they are an administrator, when they
     * created it, or when it has not been saved yet.
     *
     * A conversation is the one record where creating it says nothing about who
     * may administer it: everyone it gathers is on the same footing, so anyone
     * in it may change it or delete it.
     */
    public function canAdminister(?object $record = null): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        if (null === $record) {
            return false;
        }

        if (method_exists($record, 'getId') && null === $record->getId()) {
            return true;
        }

        if ($record instanceof Room && $record->isDirect()) {
            return $this->isIn($record);
        }

        if (method_exists($record, 'getCreator')) {
            return $this->id === $record->getCreator()?->getId();
        }

        return false;
    }

    /**
     * Whether the user is one of the people a room gathers.
     */
    public function isIn(Room $room): bool
    {
        foreach ($room->getMembers() as $member) {
            if ($member->getId() === $this->id) {
                return true;
            }
        }

        return false;
    }

    // Security

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if ($this->isAdministrator()) {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }

    public function getPassword(): ?string
    {
        return $this->passwordDigest;
    }

    public function eraseCredentials(): void
    {
    }

    // Bots

    public static function generateBotToken(): string
    {
        return Account::randomAlphanumeric(12);
    }

    /**
     * The bot authenticates with "<id>-<token>", which is used as a path segment.
     */
    public function getBotKey(): string
    {
        return \sprintf('%d-%s', $this->id, $this->botToken);
    }

    public function resetBotKey(): static
    {
        return $this->setBotToken(self::generateBotToken());
    }
}
