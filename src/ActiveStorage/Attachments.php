<?php

declare(strict_types=1);

namespace App\ActiveStorage;

use App\Entity\ActiveStorageAttachment;
use App\Entity\ActiveStorageBlob;
use App\Rails\RailsModelName;
use App\Repository\ActiveStorageAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Links uploaded files to the records that own them.
 *
 * The table is polymorphic, exactly like ActiveStorage: a record is identified
 * by its Rails model name and its identifier rather than by a foreign key. A
 * user carries one avatar, an account one logo, and a message one file.
 *
 * A page shows a whole page of messages, so reading each file with a query of
 * its own would be an N+1. The attachments read once are kept here, and a page
 * asks for all of them at once through primeFor(). The cache is dropped
 * between requests, because the application runs in worker mode.
 */
#[AutoconfigureTag('kernel.reset', ['method' => 'reset'])]
final class Attachments implements ResetInterface
{
    public const AVATAR = 'avatar';
    public const LOGO = 'logo';

    /**
     * The file a message carries. The original declares it as has_one_attached
     * under this name, so a message holds one file at most.
     */
    public const ATTACHMENT = 'attachment';

    /**
     * The attachments already read, keyed by record type, identifier and name.
     * A null value means the record carries no file under that name, which is
     * different from not read.
     *
     * @var array<string, ActiveStorageAttachment|null>
     */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActiveStorageAttachmentRepository $attachments,
        private readonly BlobStorage $storage,
        private readonly Variants $variants,
    ) {
    }

    /**
     * Attaches a blob under a name, adding to whatever is already there. This is
     * the has_many_attached behaviour.
     */
    public function attach(ActiveStorageBlob $blob, object $record, string $name): ActiveStorageAttachment
    {
        $attachment = new ActiveStorageAttachment();
        $attachment
            ->setRecordType(RailsModelName::of($record))
            ->setRecordId($this->recordId($record))
            ->setName($name)
            ->setBlob($blob);

        $this->entityManager->persist($attachment);
        $this->entityManager->flush();

        unset($this->cache[$this->key($attachment->getRecordType(), (int) $attachment->getRecordId(), $attachment->getName())]);

        return $attachment;
    }

    /**
     * Attaches a blob as the only one under a name, purging the previous one.
     * This is the has_one_attached behaviour, used by the avatar and the logo.
     */
    public function replace(ActiveStorageBlob $blob, object $record, string $name): ActiveStorageAttachment
    {
        $this->purge($record, $name);

        return $this->attach($blob, $record, $name);
    }

    public function attachmentFor(object $record, string $name): ?ActiveStorageAttachment
    {
        return $this->attachmentForRecord(RailsModelName::of($record), $this->recordId($record), $name);
    }

    /**
     * Reads the attachments of many records of the same kind in one query and
     * keeps them, so that the calls that follow do not each ask the database.
     *
     * The records are named by a model name rather than by their class, because
     * the table is polymorphic and the three room types share one name.
     *
     * @param list<int> $recordIds
     */
    public function primeFor(string $recordType, array $recordIds, string $name): void
    {
        $missing = [];

        foreach ($recordIds as $recordId) {
            $key = $this->key($recordType, $recordId, $name);

            if (!\array_key_exists($key, $this->cache)) {
                $missing[$key] = $recordId;
            }
        }

        if ([] === $missing) {
            return;
        }

        // A record with no file is remembered as null, so the page does not ask
        // for it again when it renders.
        foreach (array_keys($missing) as $key) {
            $this->cache[$key] = null;
        }

        foreach ($this->attachments->findManyFor($recordType, array_values($missing), $name) as $attachment) {
            $this->cache[$this->key($attachment->getRecordType(), (int) $attachment->getRecordId(), $attachment->getName())] = $attachment;
        }
    }

    /**
     * Drops what was read. The application runs in worker mode, so a service
     * lives across requests and the cache must not survive one.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    public function blobFor(object $record, string $name): ?ActiveStorageBlob
    {
        return $this->attachmentFor($record, $name)?->getBlob();
    }

    /**
     * @return list<ActiveStorageBlob>
     */
    public function blobsFor(object $record, string $name): array
    {
        $blobs = [];

        foreach ($this->attachments->findAllFor(RailsModelName::of($record), $this->recordId($record), $name) as $attachment) {
            $blob = $attachment->getBlob();

            if (null !== $blob) {
                $blobs[] = $blob;
            }
        }

        return $blobs;
    }

    /**
     * Drops the links without touching the files. A blob shared with another
     * record survives.
     */
    public function detach(object $record, string $name): void
    {
        $recordType = RailsModelName::of($record);
        $recordId = $this->recordId($record);

        foreach ($this->attachments->findAllFor($recordType, $recordId, $name) as $attachment) {
            $this->entityManager->remove($attachment);
        }

        $this->entityManager->flush();

        unset($this->cache[$this->key($recordType, $recordId, $name)]);
    }

    /**
     * Drops the links and the files behind them, which is what destroying an
     * attachment does in Rails.
     */
    public function purge(object $record, string $name): void
    {
        $blobs = $this->blobsFor($record, $name);

        $this->detach($record, $name);

        foreach ($blobs as $blob) {
            $this->purgeBlob($blob);
        }
    }

    /**
     * Removes a blob and its variants, unless another record still uses it.
     */
    public function purgeBlob(ActiveStorageBlob $blob): void
    {
        if ([] !== $this->attachments->findBy(['blob' => $blob])) {
            return;
        }

        $this->variants->purgeFor($blob);
        $this->storage->purge($blob);
    }

    private function attachmentForRecord(string $recordType, int $recordId, string $name): ?ActiveStorageAttachment
    {
        $key = $this->key($recordType, $recordId, $name);

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->attachments->findOneFor($recordType, $recordId, $name);
    }

    private function key(string $recordType, int $recordId, string $name): string
    {
        return $recordType.':'.$recordId.':'.$name;
    }

    private function recordId(object $record): int
    {
        if (!method_exists($record, 'getId')) {
            throw new \InvalidArgumentException(\sprintf('%s cannot carry an attachment because it has no identifier.', $record::class));
        }

        $id = $record->getId();

        if (null === $id) {
            throw new \InvalidArgumentException('A record has to be saved before a file can be attached to it.');
        }

        return (int) $id;
    }
}
