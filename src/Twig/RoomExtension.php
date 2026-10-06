<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Room;
use App\Entity\User;
use App\Room\RoomDisplayName;
use Twig\Attribute\AsTwigFunction;

/**
 * Names a room the way the reader sees it.
 *
 * The rule itself lives in the service, so the same name is shown wherever a
 * room is written down rather than only in a template.
 */
final class RoomExtension
{
    public function __construct(private readonly RoomDisplayName $names)
    {
    }

    #[AsTwigFunction('room_display_name')]
    public function displayName(Room $room, ?User $forUser = null): string
    {
        return $this->names->displayName($room, $forUser);
    }
}
