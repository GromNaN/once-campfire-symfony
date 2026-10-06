<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\Type\RailsDateTimeType;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every comparison of a timestamp binds its parameter with the rails_datetime
 * type. The column keeps microseconds and the type Doctrine infers from a
 * DateTimeImmutable drops them, which would put a page back on the message it
 * walks from instead of starting after it.
 *
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * The newest page of messages, oldest first.
     *
     * @return list<Message>
     */
    public function findLastPage(Room $room, int $size = Message::PAGE_SIZE): array
    {
        $messages = $this->pageQuery($room)
            ->orderBy('message.createdAt', 'DESC')
            ->addOrderBy('message.id', 'DESC')
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }

    /**
     * The page of messages older than the given one, oldest first.
     *
     * @return list<Message>
     */
    public function findPageBefore(Room $room, Message $message, int $size = Message::PAGE_SIZE): array
    {
        $messages = $this->pageQuery($room)
            ->andWhere('message.createdAt < :createdAt')
            ->setParameter('createdAt', $message->getCreatedAt(), RailsDateTimeType::NAME)
            ->orderBy('message.createdAt', 'DESC')
            ->addOrderBy('message.id', 'DESC')
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }

    /**
     * The page of messages newer than the given one, oldest first.
     *
     * @return list<Message>
     */
    public function findPageAfter(Room $room, Message $message, int $size = Message::PAGE_SIZE): array
    {
        return $this->pageQuery($room)
            ->andWhere('message.createdAt > :createdAt')
            ->setParameter('createdAt', $message->getCreatedAt(), RailsDateTimeType::NAME)
            ->orderBy('message.createdAt', 'ASC')
            ->addOrderBy('message.id', 'ASC')
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();
    }

    /**
     * The messages of a room created after the given moment, which is what the
     * refresh endpoint asks for.
     *
     * @return list<Message>
     */
    public function findCreatedAfter(Room $room, \DateTimeImmutable $since, int $size = Message::PAGE_SIZE): array
    {
        return $this->pageQuery($room)
            ->andWhere('message.createdAt > :since')
            ->setParameter('since', $since, RailsDateTimeType::NAME)
            ->orderBy('message.createdAt', 'ASC')
            ->addOrderBy('message.id', 'ASC')
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();
    }

    /**
     * The newest messages of a room changed after the given moment, oldest
     * first, leaving out the ones the caller already knows about.
     *
     * @param list<Message> $exclude
     *
     * @return list<Message>
     */
    public function findUpdatedAfter(Room $room, \DateTimeImmutable $since, array $exclude = [], int $size = Message::PAGE_SIZE): array
    {
        $builder = $this->pageQuery($room)
            ->andWhere('message.updatedAt > :since')
            ->setParameter('since', $since, RailsDateTimeType::NAME)
            ->orderBy('message.updatedAt', 'DESC')
            ->addOrderBy('message.id', 'DESC')
            ->setMaxResults($size);

        $ids = array_values(array_filter(array_map(static fn (Message $message) => $message->getId(), $exclude)));

        if ([] !== $ids) {
            $builder->andWhere('message.id NOT IN (:exclude)')->setParameter('exclude', $ids);
        }

        return array_reverse($builder->getQuery()->getResult());
    }

    /**
     * The messages of the given identifiers that belong to a room the user is
     * a member of, oldest first.
     *
     * Searching reads identifiers from the full text index and asks for them
     * here, so that a message the user cannot reach never leaves the database.
     *
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    public function findReachableByIds(User $user, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('message')
            ->addSelect('creator')
            ->join('message.creator', 'creator')
            ->join('message.room', 'room')
            ->join('room.memberships', 'membership')
            ->andWhere('membership.user = :user')
            ->andWhere('message.id IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
            ->orderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findInRoom(Room $room, int $id): ?Message
    {
        return $this->pageQuery($room)
            ->andWhere('message.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whether the room holds a message older than the given one, which is what
     * decides if a page has a next page towards the past.
     */
    public function hasBefore(Room $room, Message $message): bool
    {
        return null !== $this->pageQuery($room)
            ->andWhere('message.createdAt < :createdAt')
            ->setParameter('createdAt', $message->getCreatedAt(), RailsDateTimeType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whether the room holds a message newer than the given one, which is what
     * decides if a page has a next page towards the present.
     */
    public function hasAfter(Room $room, Message $message): bool
    {
        return null !== $this->pageQuery($room)
            ->andWhere('message.createdAt > :createdAt')
            ->setParameter('createdAt', $message->getCreatedAt(), RailsDateTimeType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countInRoom(Room $room): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every message a person wrote, in every room. This is what a ban walks to
     * take the words of the banned member out of the rooms they were in.
     *
     * @return list<Message>
     */
    public function findByCreatorId(int $creatorId): array
    {
        return $this->createQueryBuilder('message')
            ->addSelect('room')
            ->join('message.room', 'room')
            ->andWhere('message.creator = :creatorId')
            ->setParameter('creatorId', $creatorId)
            ->orderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function pageQuery(Room $room)
    {
        return $this->createQueryBuilder('message')
            ->addSelect('creator')
            ->join('message.creator', 'creator')
            ->andWhere('message.room = :room')
            ->setParameter('room', $room);
    }
}
