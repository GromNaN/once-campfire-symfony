<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Search;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;

/**
 * @extends ServiceEntityRepository<Search>
 */
class SearchRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, Search::class);
    }

    /**
     * Records a search, bumping its timestamp when the query is already known.
     *
     * A query that comes back to the top of the list is the reason the
     * timestamp is bumped: the recent searches are shown newest first, so
     * running an old search again has to move it up.
     */
    public function record(User $user, string $query): Search
    {
        $search = $this->findOneBy(['user' => $user, 'query' => $query]);

        if (null === $search) {
            $search = (new Search())
                ->setUser($user)
                ->setQuery($query);

            $this->getEntityManager()->persist($search);
        } else {
            $search->touchUpdatedAt($this->now());
        }

        $this->getEntityManager()->flush();

        // Only the ten most recent searches of a user are kept.
        $this->trimFor($user, $this->findRecentFor($user));

        return $search;
    }

    public function deleteFor(User $user): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * The most recently updated searches of a user, newest first.
     *
     * @return list<Search>
     */
    public function findRecentFor(User $user, int $limit = Search::MAX_RECENT): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Drops every search of a user that is not in the given list.
     *
     * @param list<Search> $keep
     */
    public function trimFor(User $user, array $keep): void
    {
        $keepIds = array_map(static fn (Search $search) => $search->getId(), $keep);

        $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.user = :user')
            ->andWhere('s.id NOT IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', [] === $keepIds ? [0] : $keepIds)
            ->getQuery()
            ->execute();
    }

    /**
     * The timestamp columns hold Rails timestamps, which are written in UTC.
     */
    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
