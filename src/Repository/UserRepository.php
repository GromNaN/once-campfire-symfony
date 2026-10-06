<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Active users, ordered by name without regard to case.
     *
     * @return list<User>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.status = :status')
            ->setParameter('status', UserStatus::Active)
            ->orderBy('LOWER(user.name)', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Users of the account that are still around, bots left out, ordered by
     * name without regard to case.
     *
     * A banned member is listed for an administrator, who is the one who can
     * lift the ban, and hidden from everyone else.
     *
     * @return list<User>
     */
    public function findForAccountOrdered(bool $includeBanned): array
    {
        $builder = $this->createQueryBuilder('user')
            ->andWhere('user.role != :bot')
            ->setParameter('bot', UserRole::Bot)
            ->orderBy('LOWER(user.name)', SortDirection::Ascending);

        if ($includeBanned) {
            $builder
                ->andWhere('user.status IN (:statuses)')
                ->setParameter('statuses', [UserStatus::Active, UserStatus::Banned]);
        } else {
            $builder
                ->andWhere('user.status = :status')
                ->setParameter('status', UserStatus::Active);
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * Bots that are still running, ordered by name without regard to case.
     *
     * A bot that was deactivated is gone from the list, and its key stops
     * working with it.
     *
     * @return list<User>
     */
    /**
     * The first administrator of the account, which is who a reader writes to
     * when something goes wrong.
     */
    public function findFirstAdministrator(): ?User
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.role = :role')
            ->setParameter('role', UserRole::Administrator)
            ->orderBy('user.id', SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveBotsOrdered(): array
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.role = :role')
            ->andWhere('user.status = :status')
            ->setParameter('role', UserRole::Bot)
            ->setParameter('status', UserStatus::Active)
            ->orderBy('LOWER(user.name)', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * A user of the account by identifier, bots left out, only while they are
     * active. A member who was deactivated or banned is not found, which is
     * what stops the administration pages from acting on them.
     */
    public function findActiveMember(int $id): ?User
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.id = :id')
            ->andWhere('user.role != :bot')
            ->andWhere('user.status = :status')
            ->setParameter('id', $id)
            ->setParameter('bot', UserRole::Bot)
            ->setParameter('status', UserStatus::Active)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A bot of the account by identifier, only while it is still running.
     */
    public function findActiveBot(int $id): ?User
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.id = :id')
            ->andWhere('user.role = :role')
            ->andWhere('user.status = :status')
            ->setParameter('id', $id)
            ->setParameter('role', UserRole::Bot)
            ->setParameter('status', UserStatus::Active)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Active users whose name holds the query, ordered by name without regard
     * to case.
     *
     * A room restricts the list to its members, which is what a mention in that
     * room can point at.
     *
     * @return list<User>
     */
    public function findForAutocomplete(?Room $room, string $query, int $limit, int $offset): array
    {
        $builder = $this->createQueryBuilder('user')
            ->andWhere('user.status = :status')
            ->setParameter('status', UserStatus::Active)
            ->orderBy('LOWER(user.name)', SortDirection::Ascending)
            ->addOrderBy('user.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $room) {
            $builder
                ->join('user.memberships', 'membership')
                ->andWhere('membership.room = :room')
                ->setParameter('room', $room);
        }

        if ('' !== $query) {
            $builder
                ->andWhere('LOWER(user.name) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * The bots of a room that are still running.
     *
     * A message is announced to every bot of a direct room, and to the bots a
     * message mentions in a shared room, which is what the identifiers narrow
     * the list to. A bot that was deactivated or banned hears nothing.
     *
     * @param list<int> $ids an empty list means every bot of the room
     *
     * @return list<User>
     */
    public function findActiveBotsInRoom(Room $room, array $ids = []): array
    {
        $builder = $this->createQueryBuilder('user')
            ->join('user.memberships', 'membership')
            ->andWhere('membership.room = :room')
            ->andWhere('user.role = :role')
            ->andWhere('user.status = :status')
            ->setParameter('room', $room)
            ->setParameter('role', UserRole::Bot)
            ->setParameter('status', UserStatus::Active)
            ->orderBy('user.id', SortDirection::Ascending);

        if ([] !== $ids) {
            $builder->andWhere('user.id IN (:ids)')->setParameter('ids', $ids);
        }

        return $builder->getQuery()->getResult();
    }
}
