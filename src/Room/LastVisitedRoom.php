<?php

declare(strict_types=1);

namespace App\Room;

use App\Entity\Room;
use App\Entity\User;
use App\Repository\MembershipRepository;

/**
 * The room a page sends a member back to.
 *
 * It is the room of the last_room cookie when they are still a member of it,
 * and the first room they joined otherwise, which is what the original
 * application calls the last room visited.
 */
final class LastVisitedRoom
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    public function for(User $user, ?string $lastRoomId): ?Room
    {
        $memberships = $this->memberships->findBy(['user' => $user], ['createdAt' => 'ASC']);

        if ([] === $memberships) {
            return null;
        }

        if (null !== $lastRoomId) {
            foreach ($memberships as $membership) {
                if ((string) $membership->getRoom()?->getId() === $lastRoomId) {
                    return $membership->getRoom();
                }
            }
        }

        return $memberships[0]->getRoom();
    }
}
