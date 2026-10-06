<?php

declare(strict_types=1);

namespace App\ActiveStorage;

use App\Entity\ActiveStorageBlob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps uploaded bytes on disk and describes them in the database.
 *
 * The layout is the one the original application uses: the file lives at
 * storage/files/<key>, the key is a random base58 string, the checksum is the
 * base64 encoded MD5 digest, and the metadata column carries the JSON the
 * analysis produced. Both applications can therefore read the same directory.
 */
final class BlobStorage
{
    public const SERVICE_NAME = 'local';

    /**
     * Alphabet of SecureRandom.base58, which is what Rails uses to draw a key.
     */
    private const KEY_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const KEY_LENGTH = 24;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageProcessor $images,
        #[Autowire('%campfire.files_dir%')]
        private readonly string $filesDir,
    ) {
    }

    /**
     * Writes the bytes to disk and returns the blob that describes them.
     */
    public function store(string $contents, string $filename, ?string $contentType = null): ActiveStorageBlob
    {
        $blob = new ActiveStorageBlob();
        $blob
            ->setKey($this->generateKey())
            ->setFilename($filename)
            ->setContentType($contentType ?? 'application/octet-stream')
            ->setServiceName(self::SERVICE_NAME)
            ->setByteSize(\strlen($contents))
            ->setChecksum(base64_encode(md5($contents, true)))
            ->setMetadata($this->metadataFor($contents, $contentType));

        $this->write($blob, $contents);

        $this->entityManager->persist($blob);
        $this->entityManager->flush();

        return $blob;
    }

    public function read(ActiveStorageBlob $blob): string
    {
        $path = $this->path($blob);
        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw new \RuntimeException(\sprintf('The file of blob "%s" is missing from %s.', $blob->getKey(), $this->filesDir));
        }

        return $contents;
    }

    public function path(ActiveStorageBlob $blob): string
    {
        return $this->filesDir.\DIRECTORY_SEPARATOR.$blob->getKey();
    }

    public function exists(ActiveStorageBlob $blob): bool
    {
        return is_file($this->path($blob));
    }

    /**
     * Removes the row and the file. The file is left alone when it is already
     * gone, so deleting twice is harmless.
     */
    public function purge(ActiveStorageBlob $blob): void
    {
        $path = $this->path($blob);

        $this->entityManager->remove($blob);
        $this->entityManager->flush();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * A random key, drawn the way ActiveStorage::Blob.generate_unique_secure_token
     * draws it.
     */
    public function generateKey(): string
    {
        $max = \strlen(self::KEY_ALPHABET) - 1;
        $key = '';

        for ($i = 0; $i < self::KEY_LENGTH; ++$i) {
            $key .= self::KEY_ALPHABET[random_int(0, $max)];
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFor(string $contents, ?string $contentType): array
    {
        $metadata = ['identified' => true, 'analyzed' => true];

        if (null !== $contentType && str_starts_with($contentType, 'image/')) {
            $size = $this->images->size($contents);

            if (null !== $size) {
                $metadata += $size;
            }
        }

        return $metadata;
    }

    private function write(ActiveStorageBlob $blob, string $contents): void
    {
        if (!is_dir($this->filesDir) && !@mkdir($this->filesDir, 0o755, true) && !is_dir($this->filesDir)) {
            throw new \RuntimeException(\sprintf('The storage directory %s could not be created.', $this->filesDir));
        }

        $path = $this->path($blob);

        if (false === @file_put_contents($path, $contents)) {
            throw new \RuntimeException(\sprintf('The file of blob "%s" could not be written to %s.', $blob->getKey(), $path));
        }
    }
}
