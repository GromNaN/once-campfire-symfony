<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\RailsDateTimeType;
use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\SessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A signed in device.
 *
 * The token is the value stored in the session cookie. The row keeps the list
 * of devices a user can review and revoke, which is why the table exists even
 * though the framework has its own session storage.
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'sessions')]
#[ORM\UniqueConstraint(name: 'index_sessions_on_token', columns: ['token'])]
#[ORM\Index(name: 'index_sessions_on_user_id', columns: ['user_id'])]
class Session implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const ACTIVITY_REFRESH_RATE = 3600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private string $token = '';

    #[ORM\Column(nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: RailsDateTimeType::NAME)]
    private \DateTimeImmutable $lastActiveAt;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'sessions')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    public function __construct()
    {
        $this->lastActiveAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getLastActiveAt(): \DateTimeImmutable
    {
        return $this->lastActiveAt;
    }

    public function setLastActiveAt(\DateTimeImmutable $lastActiveAt): static
    {
        $this->lastActiveAt = $lastActiveAt;

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

    /**
     * Generates the 24 character base58 token the original application uses.
     */
    public static function generateToken(): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $max = \strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < 24; ++$i) {
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }

    /**
     * Refreshes the device details, but at most once per hour.
     */
    public function resume(?string $userAgent, ?string $ipAddress): void
    {
        $threshold = new \DateTimeImmutable('-'.self::ACTIVITY_REFRESH_RATE.' seconds', new \DateTimeZone('UTC'));

        if ($this->lastActiveAt < $threshold) {
            $this->userAgent = $userAgent;
            $this->ipAddress = $ipAddress;
            $this->lastActiveAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }
}
