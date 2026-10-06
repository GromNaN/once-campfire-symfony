<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActiveStorageAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveStorageAttachment>
 */
class ActiveStorageAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveStorageAttachment::class);
    }

    /**
     * The attachment a record carries under the given name.
     *
     * The record is identified the way Rails identifies it, by the model name
     * and the identifier, because the table is polymorphic and cannot hold a
     * foreign key.
     */
    public function findOneFor(string $recordType, int $recordId, string $name): ?ActiveStorageAttachment
    {
        return $this->findOneBy([
            'recordType' => $recordType,
            'recordId' => $recordId,
            'name' => $name,
        ]);
    }

    /**
     * @return list<ActiveStorageAttachment>
     */
    public function findAllFor(string $recordType, int $recordId, string $name): array
    {
        return $this->findBy([
            'recordType' => $recordType,
            'recordId' => $recordId,
            'name' => $name,
        ], ['id' => 'ASC']);
    }
}
