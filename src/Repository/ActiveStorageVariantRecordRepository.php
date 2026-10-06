<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActiveStorageBlob;
use App\Entity\ActiveStorageVariantRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveStorageVariantRecord>
 */
class ActiveStorageVariantRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveStorageVariantRecord::class);
    }

    /**
     * The processed variant of a blob, or null when it has not been made yet.
     */
    public function findVariant(ActiveStorageBlob $blob, string $variationDigest): ?ActiveStorageBlob
    {
        return $this->findOneBy([
            'blob' => $blob,
            'variationDigest' => $variationDigest,
        ])?->getBlob();
    }
}
