<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Matches the integer values stored by the Rails enum on users.role.
 */
enum UserRole: int
{
    case Member = 0;
    case Administrator = 1;
    case Bot = 2;

    public function isAdministrator(): bool
    {
        return self::Administrator === $this;
    }

    public function isBot(): bool
    {
        return self::Bot === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Member => 'member',
            self::Administrator => 'administrator',
            self::Bot => 'bot',
        };
    }
}
