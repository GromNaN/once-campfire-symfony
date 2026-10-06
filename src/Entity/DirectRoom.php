<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\MembershipInvolvement;
use Doctrine\ORM\Mapping as ORM;

/**
 * A private conversation between users.
 *
 * A direct room is a singleton per set of members, and its type can never
 * change. Members are notified on every message by default.
 *
 * The repository is the one of the room hierarchy, declared on Room itself.
 * Declaring it here as well would bind the repository to the whole table, which
 * would make a lookup by this type return a room of another type.
 */
#[ORM\Entity]
class DirectRoom extends Room
{
    public const DISCRIMINATOR = 'Rooms::Direct';

    public function getDefaultInvolvement(): MembershipInvolvement
    {
        return MembershipInvolvement::Everything;
    }
}
