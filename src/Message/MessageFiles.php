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
     * client that does not know the type declares nothing useful, so the file
     * is read in that case rather than stored as an anonymous attachment.
     */
    public function attachUpload(Message $message, UploadedFile $file): ActiveStorageBlob
    {
        return $this->attach(
            $message,
            (string) file_get_contents($file->getPathname()),
            $file->getClientOriginalName(),
            $this->declaredType($file),
        );
    }

    private function declaredType(UploadedFile $file): ?string
    {
        $declared = $file->getClientMimeType();

        if (null === $declared || '' === $declared || 'application/octet-stream' === $declared) {
            return $file->getMimeType() ?: $declared;
        }

        return $declared;
    }
}
