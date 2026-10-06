<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Matches the integer values stored by the Rails enum on users.status.
 */
enum UserStatus: int
{
    case Active = 0;
    case Deactivated = 1;
    case Banned = 2;

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    public function isDeactivated(): bool
    {
        return self::Deactivated === $this;
    }

    public function isBanned(): bool
    {
        return self::Banned === $this;
    }
}
