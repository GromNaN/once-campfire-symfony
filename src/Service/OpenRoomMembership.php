<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adds a user to every open room.
 *
 * Open rooms are the ones everyone belongs to, so a user who appears in the
 * account joins them straight away, whether they signed up themselves or were
 * created as a bot. The original does this from a model callback; here it is a
 * service so both paths go through the same code.
 */
final class OpenRoomMembership
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomRepository $rooms,
    ) {
    }

    public function join(User $user): void
    {
        foreach ($this->rooms->findOpenRooms() as $room) {
            $this->entityManager->persist($room->addMember($user));
        }

        $this->entityManager->flush();
    }
}
