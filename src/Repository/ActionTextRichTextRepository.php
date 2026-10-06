<?php

namespace App\Repository;

use App\Entity\ActionTextRichText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionTextRichText>
 */
class ActionTextRichTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionTextRichText::class);
    }

    /**
     * The bodies of many records at once, which is what a page of messages
     * needs so that it does not read one body per message.
     *
     * @param list<int> $recordIds
     *
     * @return list<ActionTextRichText>
     */
    public function findBodiesFor(array $recordIds, string $recordType, string $name): array
    {
        if ([] === $recordIds) {
            return [];
        }

        return $this->createQueryBuilder('richText')
            ->andWhere('richText.recordType = :recordType')
            ->andWhere('richText.recordId IN (:recordIds)')
            ->andWhere('richText.name = :name')
            ->setParameter('recordType', $recordType)
            ->setParameter('recordIds', $recordIds)
            ->setParameter('name', $name)
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return ActionTextRichText[] Returns an array of ActionTextRichText objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?ActionTextRichText
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
