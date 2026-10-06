<?php

declare(strict_types=1);

namespace App\Bot;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The client a bot webhook is called with.
 *
 * It hands every call to the client of the application, and exists as a
 * service of its own for two reasons. An administrator may point a bot at a
 * service on their own network, which is why this client, unlike the one that
 * reads link previews, is not kept away from private addresses. And a service
 * of its own is what lets a test put a client of its own in its place without
 * touching the client the rest of the application calls.
 */
final class WebhookClient implements HttpClientInterface
{
    public function __construct(
        #[Autowire(service: 'http_client')]
        private readonly HttpClientInterface $inner,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }
}
