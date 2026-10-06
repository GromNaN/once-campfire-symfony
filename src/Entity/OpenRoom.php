<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A room open to everyone on the account. New users are granted membership
 * automatically, and an existing room that becomes open grants access to every
 * active user.
 *
 * The repository is the one of the room hierarchy, declared on Room itself.
 * Declaring it here as well would bind the repository to the whole table, which
 * would make a lookup by this type return a room of another type.
 */
#[ORM\Entity]
class OpenRoom extends Room
{
    public const DISCRIMINATOR = 'Rooms::Open';
}
