<?php

declare(strict_types=1);

namespace App\ActiveStorage;

use App\Entity\ActiveStorageBlob;
use App\Entity\ActiveStorageVariantRecord;
use App\Repository\ActiveStorageVariantRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes the resized copies of an uploaded image.
 *
 * A variant is itself a blob, and the variant record table remembers which
 * blob was made from which source, so a thumbnail is only processed once. The
 * variations match the ones the original application declares: a square webp
 * for an avatar, a large and a small png for an account logo, and a thumbnail
 * for a file attached to a message.
 */
final class Variants
{
    public const SQUARE = 'square';
    public const LARGE = 'large';
    public const SMALL = 'small';

    /**
     * The thumbnail of an attachment, shrunk to fit the box the original
     * declares for it. It is encoded as webp like the other variants, which
     * keeps a picture of a few megabytes down to a few hundred kilobytes.
     */
    public const THUMB = 'thumb';

    /**
     * @var array<string, array{width: int, height: int, format: string}>
     */
    private const SPECS = [
        self::SQUARE => ['width' => 512, 'height' => 512, 'format' => ImageProcessor::FORMAT_WEBP],
        self::LARGE => ['width' => 512, 'height' => 512, 'format' => ImageProcessor::FORMAT_PNG],
        self::SMALL => ['width' => 192, 'height' => 192, 'format' => ImageProcessor::FORMAT_PNG],
        self::THUMB => ['width' => 1200, 'height' => 800, 'format' => ImageProcessor::FORMAT_WEBP],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActiveStorageVariantRecordRepository $records,
        private readonly BlobStorage $storage,
        private readonly ImageProcessor $images,
    ) {
    }

    /**
     * The processed variant, made on first use and kept afterwards. Returns null
     * when the blob is not an image the driver can read.
     */
    public function of(ActiveStorageBlob $blob, string $name): ?ActiveStorageBlob
    {
        $spec = self::SPECS[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown variant "%s".', $name));
        $digest = $this->digest($name, $spec);

        $record = $this->records->findOneBy(['blob' => $blob, 'variationDigest' => $digest]);
        $existing = $record?->getVariantBlob();

        if (null !== $existing && $this->storage->exists($existing)) {
            return $existing;
        }

        $processed = $this->images->scaleDown(
            $this->storage->read($blob),
            $spec['width'],
            $spec['height'],
            $spec['format'],
        );

        if (null === $processed) {
            return null;
        }

        $variant = $this->storage->store(
            $processed,
            self::filename($blob->getFilename(), $spec['format']),
            $this->images->mediaType($spec['format']),
        );

        $record ??= new ActiveStorageVariantRecord();
        $record->setBlob($blob)->setVariantBlob($variant)->setVariationDigest($digest);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $variant;
    }

    /**
     * Removes the variants made from a blob, with their files. Called when the
     * source blob itself is purged.
     */
    public function purgeFor(ActiveStorageBlob $blob): void
    {
        foreach ($this->records->findBy(['blob' => $blob]) as $record) {
            $variant = $record->getVariantBlob();

            $this->entityManager->remove($record);
            $this->entityManager->flush();

            if (null !== $variant) {
                $this->storage->purge($variant);
            }
        }
    }

    /**
     * @param array{width: int, height: int, format: string} $spec
     */
    private function digest(string $name, array $spec): string
    {
        $json = json_encode([$name, $spec], \JSON_THROW_ON_ERROR);

        return base64_encode(sha1($json, true));
    }

    /**
     * The variant keeps the name of the original file with the extension of the
     * format it was encoded in, which is what ActiveStorage::Variant does.
     */
    private static function filename(string $filename, string $format): string
    {
        $base = pathinfo($filename, \PATHINFO_FILENAME);

        return \sprintf('%s.%s', '' === $base ? 'variant' : $base, $format);
    }
}
