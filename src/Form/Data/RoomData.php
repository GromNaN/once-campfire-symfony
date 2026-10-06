<?php

declare(strict_types=1);

namespace App\Form\Data;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the fields of a room form: its name and the members to grant.
 *
 * A direct room is named after the people in it, so its form does not ask for a
 * name, and an open room belongs to everyone, so its form does not ask for
 * members. Each constraint is therefore grouped, and each form runs the groups
 * it asks the reader about.
 */
final class RoomData
{
    #[Assert\NotBlank(message: 'Enter a name.', groups: ['room'])]
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.', groups: ['room'])]
    public ?string $name = null;

    /**
     * @var list<User>
     */
    #[Assert\Count(min: 1, minMessage: 'Pick at least one person.', groups: ['members'])]
    public array $members = [];
}
