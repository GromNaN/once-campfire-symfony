<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\BanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An address that is refused access, recorded when a user is banned.
 */
#[ORM\Entity(repositoryClass: BanRepository::class)]
#[ORM\Table(name: 'bans')]
#[ORM\Index(name: 'index_bans_on_ip_address', columns: ['ip_address'])]
#[ORM\Index(name: 'index_bans_on_user_id', columns: ['user_id'])]
class Ban implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private string $ipAddress = '';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'bans')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

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
