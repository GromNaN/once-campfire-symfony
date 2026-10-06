<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A browser push endpoint registered by a user.
 *
 * The table is named push_subscriptions in the original schema, without a
 * namespace separator, so the name is set explicitly.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscriptions')]
#[ORM\Index(name: 'idx_on_endpoint_p256dh_key_auth_key_7553014576', columns: ['endpoint', 'p256dh_key', 'auth_key'])]
#[ORM\Index(name: 'index_push_subscriptions_on_user_id', columns: ['user_id'])]
class PushSubscription implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    /**
     * Hosts allowed to receive a push, taken from the original implementation.
     * Anything else is refused so a crafted endpoint cannot turn the server
     * into a request forwarder.
     */
    public const PERMITTED_ENDPOINT_HOSTS = [
        'jmt17.google.com',
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        'notify.windows.com',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?string $endpoint = null;

    #[ORM\Column(nullable: true)]
    private ?string $p256dhKey = null;

    #[ORM\Column(nullable: true)]
    private ?string $authKey = null;

    #[ORM\Column(nullable: true)]
    private ?string $userAgent = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'pushSubscriptions')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getP256dhKey(): ?string
    {
        return $this->p256dhKey;
    }

    public function setP256dhKey(?string $p256dhKey): static
    {
        $this->p256dhKey = $p256dhKey;

        return $this;
    }

    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    public function setAuthKey(?string $authKey): static
    {
        $this->authKey = $authKey;

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
     * Refuses an endpoint that is not a push service, which is what keeps a
     * crafted address from turning the server into a request forwarder.
     */
    #[Assert\Callback]
    public function validateEndpoint(ExecutionContextInterface $context): void
    {
        if (!$this->isPermittedEndpoint()) {
            $context->buildViolation('This is not a permitted push service.')
                ->atPath('endpoint')
                ->addViolation();
        }
    }

    public function isPermittedEndpoint(): bool
    {
        $host = parse_url((string) $this->endpoint, \PHP_URL_HOST);

        if (!\is_string($host) || '' === $host) {
            return false;
        }

        $scheme = parse_url((string) $this->endpoint, \PHP_URL_SCHEME);
        $port = parse_url((string) $this->endpoint, \PHP_URL_PORT);

        if ('https' !== $scheme || (null !== $port && 443 !== $port)) {
            return false;
        }

        $host = strtolower($host);

        foreach (self::PERMITTED_ENDPOINT_HOSTS as $permitted) {
            if ($host === $permitted || str_ends_with($host, '.'.$permitted)) {
                return true;
            }
        }

        return false;
    }
}
