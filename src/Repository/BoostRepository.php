<?php

namespace App\Repository;

use App\Entity\Boost;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Boost>
 */
class BoostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Boost::class);
    }

    /**
     * The boosts of a message, oldest first, with the booster already loaded.
     *
     * @return list<Boost>
     */
    public function findOrderedFor(Message $message): array
    {
        return $this->createQueryBuilder('boost')
            ->addSelect('booster')
            ->join('boost.booster', 'booster')
            ->andWhere('boost.message = :message')
            ->setParameter('message', $message)
            ->orderBy('boost.createdAt', SortDirection::Ascending)
            ->addOrderBy('boost.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
