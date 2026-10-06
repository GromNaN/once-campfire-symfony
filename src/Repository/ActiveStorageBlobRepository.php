<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActiveStorageBlob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveStorageBlob>
 */
class ActiveStorageBlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveStorageBlob::class);
    }
}
