<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DirectRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates rooms and keeps their memberships in sync.
 */
final class RoomManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomRepository $rooms,
    ) {
    }

    /**
     * Saves a room and grants membership to the given users.
     *
     * @param iterable<User> $users
     */
    public function createFor(Room $room, iterable $users): Room
    {
        return $this->entityManager->wrapInTransaction(function () use ($room, $users): Room {
            $this->entityManager->persist($room);

            foreach ($users as $user) {
                $this->entityManager->persist($room->addMember($user));
            }

            $this->entityManager->flush();

            return $room;
        });
    }

    /**
     * @param iterable<User> $users
     */
    public function grant(Room $room, iterable $users): void
    {
        $this->entityManager->wrapInTransaction(function () use ($room, $users): void {
            foreach ($users as $user) {
                $this->entityManager->persist($room->addMember($user));
            }

            $this->entityManager->flush();
        });
    }

    /**
     * @param iterable<User> $users
     */
    public function revoke(Room $room, iterable $users): void
    {
        $this->entityManager->wrapInTransaction(function () use ($room, $users): void {
            foreach ($users as $user) {
                foreach ($room->getMemberships() as $membership) {
                    if ($membership->getUser()?->getId() === $user->getId()) {
                        $room->getMemberships()->removeElement($membership);
                        $this->entityManager->remove($membership);
                    }
                }
            }

            $this->entityManager->flush();
        });
    }

    /**
     * Makes the members of a room be exactly the given users.
     *
     * An edit page shows the whole account, with a switch per person, so what
     * comes back is the full list of who is in the room rather than the change
     * from what it was. Whoever is missing from it loses access.
     *
     * @param list<User> $users
     */
    public function revise(Room $room, array $users): void
    {
        // Granting and revoking are one edit: a member who joins the list while
        // another leaves is not left half applied when one side fails.
        $this->entityManager->wrapInTransaction(function () use ($room, $users): void {
            $wanted = $this->sortedIds($users);
            $current = $this->sortedIds($room->getMembers());

            $this->grant($room, array_filter(
                $users,
                static fn (User $user): bool => !\in_array($user->getId(), $current, true),
            ));

            $this->revoke($room, array_filter(
                $room->getMembers(),
                static fn (User $user): bool => !\in_array($user->getId(), $wanted, true),
            ));
        });
    }

    /**
     * Turns a room of one kind into a room of another kind.
     *
     * The three kinds of room share one table, and the kind is a column of that
     * table. The ORM cannot move a record that is already loaded from one class
     * of a hierarchy to another, so the column is written directly, the room is
     * set aside, and it is read back as the kind it now is. Only that room is
     * set aside: the users a form submitted stay loaded.
     *
     * @param class-string<Room> $class
     */
    public function changeType(Room $room, string $class): Room
    {
        if ($room instanceof $class) {
            return $room;
        }

        $id = $room->getId();
        \assert(null !== $id);

        $this->entityManager->getConnection()->update(
            'rooms',
            ['type' => $class::DISCRIMINATOR],
            ['id' => $id],
        );

        $this->entityManager->detach($room);

        $converted = $this->entityManager->find(Room::class, $id);
        \assert($converted instanceof Room);

        return $converted;
    }

    /**
     * Returns the direct room that gathers exactly these users, creating it when
     * it does not exist yet. A direct room is a singleton for its participants.
     *
     * @param list<User> $users
     */
    public function findOrCreateDirect(array $users): DirectRoom
    {
        $wanted = $this->sortedIds($users);

        foreach ($this->rooms->findDirectRooms() as $room) {
            if ($this->sortedIds($room->getMembers()) === $wanted) {
                return $room;
            }
        }

        $room = new DirectRoom();
        $room->setCreator($users[0]);

        /** @var DirectRoom $created */
        $created = $this->createFor($room, $users);

        return $created;
    }

    /**
     * @param iterable<User> $users
     *
     * @return list<int>
     */
    private function sortedIds(iterable $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            $ids[] = (int) $user->getId();
        }

        sort($ids);

        return $ids;
    }
}
