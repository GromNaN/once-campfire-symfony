<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\Timestamps;
use App\Entity\Concern\UpdatedAtAware;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The single account of an installation.
 *
 * A unique index on singleton_guard, whose every row stores 0, makes a second
 * account impossible. This mirrors the original Rails model.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\UniqueConstraint(name: 'index_accounts_on_singleton_guard', columns: ['singleton_guard'])]
#[ORM\HasLifecycleCallbacks]
class Account implements CreatedAtAware, UpdatedAtAware
{
    use Timestamps;

    public const DEFAULT_NAME = 'Campfire';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private string $name = self::DEFAULT_NAME;

    #[ORM\Column]
    private string $joinCode = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customStyles = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $singletonGuard = 0;

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

    public function getJoinCode(): string
    {
        return $this->joinCode;
    }

    public function setJoinCode(string $joinCode): static
    {
        $this->joinCode = $joinCode;

        return $this;
    }

    public function getCustomStyles(): ?string
    {
        return $this->customStyles;
    }

    public function setCustomStyles(?string $customStyles): static
    {
        $this->customStyles = $customStyles;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings ?? [];
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->getSettings()[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): static
    {
        $settings = $this->getSettings();
        $settings[$key] = $value;
        $this->settings = $settings;

        return $this;
    }

    public function restrictRoomCreationToAdministrators(): bool
    {
        return (bool) $this->getSetting('restrict_room_creation_to_administrators', false);
    }

    public function setRestrictRoomCreationToAdministrators(bool $value): static
    {
        return $this->setSetting('restrict_room_creation_to_administrators', $value);
    }

    public function resetJoinCode(): static
    {
        return $this->setJoinCode(self::generateJoinCode());
    }

    /**
     * Twelve random alphanumeric characters split into groups of four, so the
     * code reads as XXXX-XXXX-XXXX, exactly like the original.
     */
    public static function generateJoinCode(): string
    {
        return implode('-', str_split(self::randomAlphanumeric(12), 4));
    }

    public static function randomAlphanumeric(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = \strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; ++$i) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    #[ORM\PrePersist]
    public function assignJoinCode(): void
    {
        if ('' === $this->joinCode) {
            $this->joinCode = self::generateJoinCode();
        }
    }
}
