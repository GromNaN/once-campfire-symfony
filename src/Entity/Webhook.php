<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\WebhookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The endpoint a bot calls when a message is posted in a room it belongs to.
 *
 * The association is many to one, matching the "belongs_to :user" of the
 * original, because the Rails schema puts no unique index on user_id. A bot
 * has one webhook in practice.
 */
#[ORM\Entity(repositoryClass: WebhookRepository::class)]
#[ORM\Table(name: 'webhooks')]
#[ORM\Index(name: 'index_webhooks_on_user_id', columns: ['user_id'])]
class Webhook implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const ENDPOINT_TIMEOUT = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?string $url = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'webhooks')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

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
}
