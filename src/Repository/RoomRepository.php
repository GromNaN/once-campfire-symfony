<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DirectRoom;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * Every open room, which is what a new member is added to.
     *
     * @return list<OpenRoom>
     */
    public function findOpenRooms(): array
    {
        // Querying the subclass makes Doctrine add the discriminator condition.
        return $this->getEntityManager()->createQueryBuilder()
            ->select('room')
            ->from(OpenRoom::class, 'room')
            ->orderBy('room.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<DirectRoom>
     */
    public function findDirectRooms(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('room', 'membership', 'participant')
            ->from(DirectRoom::class, 'room')
            ->leftJoin('room.memberships', 'membership')
            ->leftJoin('membership.user', 'participant')
            ->orderBy('room.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The room the account was created with, which is the oldest one.
     *
     * It is the room that shows how to invite people, because it is the one
     * everyone lands in when the account is new.
     */
    public function findOriginal(): ?Room
    {
        return $this->createQueryBuilder('room')
            ->orderBy('room.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The most recently created room the user is a member of, which is where
     * the room index sends them.
     */
    public function findLastForUser(User $user): ?Room
    {
        return $this->createQueryBuilder('room')
            ->join('room.memberships', 'membership')
            ->andWhere('membership.user = :user')
            ->setParameter('user', $user)
            ->orderBy('room.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Finds a room the user is a member of. Direct rooms are excluded unless the
     * caller asks for them, so that one room namespace cannot reach another.
     *
     * @param class-string<Room>|null $type
     */
    public function findForUser(int $id, User $user, ?string $type = null): ?Room
    {
        $builder = $this->createQueryBuilder('room')
            ->join('room.memberships', 'membership')
            ->andWhere('membership.user = :user')
            ->andWhere('room.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id);

        if (null !== $type) {
            $builder
                ->andWhere('room INSTANCE OF :type')
                // Doctrine reads the kind of room from the metadata it is given
                // here, not from the name of a class, so it is handed the
                // metadata rather than the name.
                ->setParameter('type', $this->getEntityManager()->getClassMetadata($type));
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    /**
     * Finds a room the user is a member of that is not a private conversation.
     *
     * This is the scope the pages that edit a room work in: an open room and a
     * closed room turn into each other, so both are in reach there, while a
     * conversation never is.
     */
    public function findNonDirectForUser(int $id, User $user): ?Room
    {
        return $this->createQueryBuilder('room')
            ->join('room.memberships', 'membership')
            ->andWhere('membership.user = :user')
            ->andWhere('room.id = :id')
            ->andWhere('room NOT INSTANCE OF :direct')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            // The kind of room is read from metadata, not from a class name.
            ->setParameter('direct', $this->getEntityManager()->getClassMetadata(DirectRoom::class))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
