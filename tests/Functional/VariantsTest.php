<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActiveStorage\BlobStorage;
use App\ActiveStorage\Variants;
use App\Entity\ActiveStorageBlob;
use App\Entity\ActiveStorageVariantRecord;

/**
 * Covers the resized copies of an uploaded image: a variant is made once, every
 * later call answers with the same processed blob rather than the original, and
 * purging the variants removes the processed blob and its file without touching
 * the source.
 */
final class VariantsTest extends DatabaseTestCase
{
    public function testTheVariantIsMadeOnceAndTheSameProcessedBlobIsReturned(): void
    {
        $blob = $this->storePicture(800, 600);
        $variants = static::getContainer()->get(Variants::class);

        $first = $variants->of($blob, Variants::SQUARE);
        self::assertInstanceOf(ActiveStorageBlob::class, $first);

        $second = $variants->of($blob, Variants::SQUARE);
        self::assertInstanceOf(ActiveStorageBlob::class, $second);

        // The second call answers the cached variant, not the source blob.
        self::assertSame($first->getId(), $second->getId());
        self::assertNotSame($blob->getId(), $second->getId());
        self::assertNotSame($blob->getKey(), $second->getKey());

        // The variant holds the resized bytes, encoded as webp, not the source.
        self::assertSame('image/webp', $second->getContentType());
        self::assertNotSame($this->storage()->read($blob), $this->storage()->read($second));

        $image = imagecreatefromstring($this->storage()->read($second));
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame(512, imagesx($image));
        self::assertSame(384, imagesy($image));

        // One record links the source blob to its variant blob.
        $records = $this->entityManager()->getRepository(ActiveStorageVariantRecord::class)->findAll();
        self::assertCount(1, $records);
        self::assertSame($blob->getId(), $records[0]->getBlob()?->getId());
        self::assertSame($second->getId(), $records[0]->getVariantBlob()?->getId());
    }

    public function testPurgingTheVariantsRemovesTheVariantBlobAndKeepsTheSource(): void
    {
        $blob = $this->storePicture(800, 600);
        $variants = static::getContainer()->get(Variants::class);

        $variant = $variants->of($blob, Variants::SQUARE);
        self::assertInstanceOf(ActiveStorageBlob::class, $variant);

        $variantId = $variant->getId();
        $sourceId = $blob->getId();
        $variantPath = $this->storage()->path($variant);
        $sourcePath = $this->storage()->path($blob);
        self::assertFileExists($variantPath);

        $variants->purgeFor($blob);

        // The variant blob, its file and its record are gone.
        self::assertNull($this->entityManager()->getRepository(ActiveStorageBlob::class)->find($variantId));
        self::assertFileDoesNotExist($variantPath);
        self::assertCount(0, $this->entityManager()->getRepository(ActiveStorageVariantRecord::class)->findAll());

        // The source blob and its file are untouched.
        self::assertNotNull($this->entityManager()->getRepository(ActiveStorageBlob::class)->find($sourceId));
        self::assertFileExists($sourcePath);
    }

    /**
     * Stores a plain picture as a source blob, the way an upload would.
     */
    private function storePicture(int $width, int $height): ActiveStorageBlob
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 40));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $this->storage()->store($bytes, 'picture.png', 'image/png');
    }

    private function storage(): BlobStorage
    {
        return static::getContainer()->get(BlobStorage::class);
    }
}
