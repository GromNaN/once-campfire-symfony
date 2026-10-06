<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\SearchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A search the user ran, kept so the search page can suggest recent queries.
 * Only the ten most recent searches of a user are kept.
 */
#[ORM\Entity(repositoryClass: SearchRepository::class)]
#[ORM\Table(name: 'searches')]
#[ORM\Index(name: 'index_searches_on_user_id', columns: ['user_id'])]
class Search implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const MAX_RECENT = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'query')]
    private string $query = '';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'searches')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): static
    {
        $this->query = $query;

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
