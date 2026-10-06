<?php

declare(strict_types=1);

namespace App\Controller\Rails;

use App\ActionText\SignedId;
use App\ActiveStorage\BlobStorage;
use App\ActiveStorage\Variants;
use App\Entity\ActiveStorageBlob;
use App\Rails\RailsModelName;
use App\Repository\ActiveStorageBlobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves an uploaded file.
 *
 * The address carries a signed identifier instead of the row identifier, so
 * the address itself is the permission and reading is public: that is how the
 * original application serves a blob, and it is what lets a picture be shown
 * to a browser without the session being involved. A file is only readable
 * while its identifier is known, and the identifier is only written into the
 * pages that already show the file.
 *
 * The optional variant parameter asks for a resized copy, made on first use.
 */
final class ActiveStorageBlobsController extends AbstractController
{
    private const CACHE_SECONDS = 604800;

    public function __construct(
        private readonly ActiveStorageBlobRepository $blobs,
        private readonly BlobStorage $storage,
        private readonly Variants $variants,
        private readonly SignedId $signedIds,
    ) {
    }

    #[Route(
        '/rails/active_storage/blobs/{signed_id}/{filename}',
        name: 'rails_blob',
        methods: ['GET'],
        requirements: ['signed_id' => '[^/]+', 'filename' => '[^/]+'],
    )]
    public function show(Request $request, string $signed_id, string $filename): Response
    {
        $blob = $this->blob($signed_id);

        if (null === $blob) {
            throw $this->createNotFoundException();
        }

        $variant = $request->query->get('variant');

        if (\is_string($variant) && '' !== $variant) {
            $blob = $this->variants->of($blob, $variant);

            if (null === $blob) {
                throw $this->createNotFoundException();
            }
        }

        if (!$this->storage->exists($blob)) {
            throw $this->createNotFoundException();
        }

        $response = new Response($this->storage->read($blob));
        $response->headers->set('Content-Type', $blob->getContentType() ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', $this->disposition($request, $blob));
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setEtag(md5((string) $blob->getChecksum().'-'.(string) $blob->getId()));

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    /**
     * The identifier names the row, and the purpose it was signed for is what
     * keeps a token made for something else from reading a file.
     */
    private function blob(string $signedId): ?ActiveStorageBlob
    {
        $decoded = $this->signedIds->decode($signedId);

        if (null === $decoded || RailsModelName::BLOB !== $decoded['model'] || SignedId::PURPOSE_BLOB !== $decoded['purpose']) {
            return null;
        }

        return $this->blobs->find((int) $decoded['id']);
    }

    private function disposition(Request $request, ActiveStorageBlob $blob): string
    {
        $attachment = 'attachment' === $request->query->get('disposition');

        return HeaderUtils::makeDisposition(
            $attachment ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $this->filename($blob),
            'file',
        );
    }

    /**
     * A header cannot carry a control character, and a name uploaded from a
     * browser can hold one, so the name is cleaned before it is written.
     */
    private function filename(ActiveStorageBlob $blob): string
    {
        $name = (string) preg_replace('/[\p{C}]+/u', '', basename($blob->getFilename()));

        return '' === trim($name) ? 'file' : $name;
    }
}
