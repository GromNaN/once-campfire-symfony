<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DirectRoom;
use App\Entity\Enum\MembershipInvolvement;
use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    public function findFor(Room $room, User $user): ?Membership
    {
        return $this->findOneBy(['room' => $room, 'user' => $user]);
    }

    /**
     * Takes a user out of every shared room, keeping the direct conversations.
     *
     * A deactivated member is gone from the rooms they were invited to, but a
     * direct conversation belongs to the two people in it and stays readable by
     * the other one, which is why only the shared rooms are dropped.
     */
    public function deleteNonDirectFor(User $user): void
    {
        // Rooms of a single table hierarchy: naming the direct ones in a
        // subquery is what leaves them out, because Doctrine adds the
        // discriminator of the subclass to it.
        $this->createQueryBuilder('membership')
            ->delete()
            ->andWhere('membership.user = :user')
            ->andWhere('membership.room NOT IN (SELECT direct.id FROM '.DirectRoom::class.' direct)')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Every membership of a user, whatever the involvement, with the room
     * loaded and ordered by name.
     *
     * @return list<Membership>
     */
    public function findAllForUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('r')
            ->join('m.room', 'r')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('LOWER(r.name)', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * The identifiers of the rooms a user is a member of, oldest membership
     * first.
     *
     * The Mercure topics of a user name their rooms, and they are built on
     * every authenticated response, so the rooms are read as identifiers in one
     * query rather than as memberships with their room loaded one by one.
     *
     * @return list<int>
     */
    public function findRoomIdsForUser(User $user): array
    {
        $ids = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.room)')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.id', SortDirection::Ascending)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(intval(...), array_filter($ids, static fn ($id) => null !== $id)));
    }

    /**
     * Members of the room who asked to see it and are not connected right now.
     *
     * Those are the ones a new message leaves unread: someone reading the room
     * live has nothing to catch up on.
     *
     * @return list<Membership>
     */
    public function findToMarkUnread(Room $room, User $author): array
    {
        $memberships = $this->createQueryBuilder('m')
            ->andWhere('m.room = :room')
            ->andWhere('m.user != :author')
            ->andWhere('m.involvement != :invisible')
            ->setParameter('room', $room)
            ->setParameter('author', $author)
            ->setParameter('invisible', MembershipInvolvement::Invisible)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $memberships,
            static fn (Membership $membership) => !$membership->isConnected(),
        ));
    }

    /**
     * How many rooms have something new for a user.
     *
     * This is the number painted on the icon of the application by a push
     * notification, so it is counted per user rather than per subscription.
     */
    public function countUnreadForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.user = :user')
            ->andWhere('m.unreadAt IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Members of the room who can see it, for the room list.
     *
     * @return list<Membership>
     */
    public function findVisibleForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('r')
            ->join('m.room', 'r')
            ->andWhere('m.user = :user')
            ->andWhere('m.involvement != :invisible')
            ->setParameter('user', $user)
            ->setParameter('invisible', MembershipInvolvement::Invisible)
            ->orderBy('LOWER(r.name)', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
