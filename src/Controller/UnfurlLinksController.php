<?php

declare(strict_types=1);

namespace App\Controller;

use App\Opengraph\Fetcher;
use App\Opengraph\Metadata;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Describes the page behind a link someone pasted.
 *
 * The composer posts the address here and shows what comes back next to the
 * message. A page that says nothing, or that cannot be read, answers with an
 * empty response and the composer shows the plain link.
 */
final class UnfurlLinksController extends AbstractController
{
    public function __construct(private readonly Fetcher $fetcher)
    {
    }

    #[Route('/unfurl_link', name: 'unfurl_link', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $url = trim($request->getPayload()->getString('url'));

        if ('' === $url) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $metadata = Metadata::fromUrl($this->fetcher, $url);

        return null === $metadata
            ? new Response('', Response::HTTP_NO_CONTENT)
            : new JsonResponse($metadata->toArray());
    }
}
