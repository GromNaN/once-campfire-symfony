<?php

declare(strict_types=1);

namespace App\ActiveStorage;

use App\Entity\ActiveStorageAttachment;
use App\Entity\ActiveStorageBlob;
use App\Rails\RailsModelName;
use App\Repository\ActiveStorageAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Links uploaded files to the records that own them.
 *
 * The table is polymorphic, exactly like ActiveStorage: a record is identified
 * by its Rails model name and its identifier rather than by a foreign key. A
 * user carries one avatar, an account one logo, and a message one file.
 */
final class Attachments
{
    public const AVATAR = 'avatar';
    public const LOGO = 'logo';

    /**
     * The file a message carries. The original declares it as has_one_attached
     * under this name, so a message holds one file at most.
     */
    public const ATTACHMENT = 'attachment';

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
        return $this->attachments->findOneFor(RailsModelName::of($record), $this->recordId($record), $name);
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
        foreach ($this->attachments->findAllFor(RailsModelName::of($record), $this->recordId($record), $name) as $attachment) {
            $this->entityManager->remove($attachment);
        }

        $this->entityManager->flush();
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
