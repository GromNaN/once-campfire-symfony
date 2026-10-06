<?php

declare(strict_types=1);

namespace App\Opengraph;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads the page behind a link someone posted.
 *
 * The client handed to this class is the guarded one, which refuses to reach a
 * private network. Everything here is written to give up quickly: a page that
 * takes too long, that is not HTML, or that is too large to be a page someone
 * meant to link to is not worth a preview.
 */
final class Fetcher
{
    public const MAX_REDIRECTS = 10;

    /** Five megabytes, the same limit the original application uses. */
    public const MAX_BODY_SIZE = 5 * 1024 * 1024;

    private const DOCUMENT_CONTENT_TYPE = 'text/html';
    private const TIMEOUT = 5;

    public function __construct(
        #[Target('app.opengraph.http_client')]
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * The HTML of a page, or null when it cannot be read.
     */
    public function document(string $url): ?string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => self::TIMEOUT,
                'headers' => ['Accept' => self::DOCUMENT_CONTENT_TYPE],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            if (self::DOCUMENT_CONTENT_TYPE !== $this->contentType($response)) {
                return null;
            }

            $declared = $response->getHeaders(false)['content-length'][0] ?? null;

            if (null !== $declared && (int) $declared > self::MAX_BODY_SIZE) {
                return null;
            }

            return $this->body($response);
        } catch (HttpException) {
            return null;
        }
    }

    /**
     * The type of a file, asked for without downloading it. A site that does
     * not answer, or does not say what it serves, tells us nothing.
     */
    public function contentTypeOf(string $url): ?string
    {
        try {
            $response = $this->client->request('HEAD', $url, [
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => self::TIMEOUT,
            ]);

            return 200 === $response->getStatusCode() ? $this->contentType($response) : null;
        } catch (HttpException) {
            return null;
        }
    }

    /**
     * Reads the body chunk by chunk, so that a page announcing a small body and
     * sending a large one is dropped before it fills the memory.
     */
    private function body(ResponseInterface $response): ?string
    {
        $body = '';

        foreach ($this->client->stream($response) as $chunk) {
            $body .= $chunk->getContent();

            if (\strlen($body) > self::MAX_BODY_SIZE) {
                $response->cancel();

                return null;
            }
        }

        return $body;
    }

    /**
     * The type alone, without the parameters a server appends to it.
     */
    private function contentType(ResponseInterface $response): ?string
    {
        $header = $response->getHeaders(false)['content-type'][0] ?? null;

        return null === $header ? null : strtolower(trim(explode(';', $header)[0]));
    }
}
