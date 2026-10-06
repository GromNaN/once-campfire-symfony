<?php

declare(strict_types=1);

namespace App\Message;

use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\Entity\ActiveStorageBlob;
use App\Entity\Message;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores the file a message carries.
 *
 * A message holds one file at most, which replaces whatever was there before,
 * so a member uploading a picture and a bot answering with a document go
 * through the same two steps: write the bytes, then link them to the message.
 */
final class MessageFiles
{
    /**
     * The largest upload a message may carry. A larger one is refused before
     * its bytes are read into memory.
     */
    private const MAX_SIZE = 100 * 1024 * 1024;

    public function __construct(
        private readonly BlobStorage $storage,
        private readonly Attachments $attachments,
    ) {
    }

    /**
     * The content type is the one the caller declared. It is stored as it is,
     * because it is what decides how the file is shown and offered back.
     */
    public function attach(Message $message, string $contents, string $filename, ?string $contentType): ActiveStorageBlob
    {
        $blob = $this->storage->store($contents, $filename, $contentType);
        $this->attachments->replace($blob, $message, Attachments::ATTACHMENT);

        return $blob;
    }

    /**
     * Stores a file an upload sent.
     *
     * The content type is the one the caller declared, which is what the
     * original application stores and what decides how a file is shown. A
     * client that declares nothing useful, or something that does not match
     * the bytes, gets the type detected from the file instead.
     */
    public function attachUpload(Message $message, UploadedFile $file): ActiveStorageBlob
    {
        $size = $file->getSize();

        if (false !== $size && $size > self::MAX_SIZE) {
            throw new \InvalidArgumentException(\sprintf(
                'The file "%s" is larger than the %d MB a message may carry.',
                $file->getClientOriginalName(),
                intdiv(self::MAX_SIZE, 1024 * 1024),
            ));
        }

        return $this->attach(
            $message,
            (string) file_get_contents($file->getPathname()),
            $file->getClientOriginalName(),
            $this->declaredType($file),
        );
    }

    /**
     * The type to store for an upload.
     *
     * The declared type comes from the client, so it is only kept when it is a
     * well formed type that does not contradict what the bytes really are. A
     * client that declares nothing useful gets the detected type.
     */
    private function declaredType(UploadedFile $file): string
    {
        $declared = $file->getClientMimeType();
        $detected = $file->getMimeType() ?: 'application/octet-stream';

        if (null !== $declared
            && self::isWellFormedType($declared)
            && ('application/octet-stream' === $detected || self::categoryOf($declared) === self::categoryOf($detected))
        ) {
            return $declared;
        }

        return $detected;
    }

    private static function isWellFormedType(string $type): bool
    {
        return 1 === preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}$~i', $type);
    }

    private static function categoryOf(string $type): string
    {
        return strtolower(substr($type, 0, (int) strpos($type, '/')));
    }
}
