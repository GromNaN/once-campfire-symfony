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

    /**
     * The attachments of many records of the same kind at once, which is what a
     * page of messages needs so that it does not read one file per message.
     *
     * @param list<int> $recordIds
     *
     * @return list<ActiveStorageAttachment>
     */
    public function findManyFor(string $recordType, array $recordIds, string $name): array
    {
        if ([] === $recordIds) {
            return [];
        }

        return $this->findBy([
            'recordType' => $recordType,
            'recordId' => $recordIds,
            'name' => $name,
        ], ['id' => 'ASC']);
    }
}
