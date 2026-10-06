<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wraps the Mercure hub so publishing works in every context.
 *
 * Under FrankenPHP the hub lives in the same process, and the bundle's
 * FrankenPhpHub publishes through the native mercure_publish() function.
 * That function does not exist when the code runs from the CLI or from the
 * test suite, so this decorator falls back to an HTTP publish in development
 * and records updates in memory during tests.
 */
final class HubDecorator implements HubInterface
{
    /** @var list<Update> */
    private array $publishedUpdates = [];

    private ?Hub $httpHub = null;

    public function __construct(
        private readonly HubInterface $inner,
        private readonly string $mercureUrl,
        private readonly string $environment,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->inner->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->inner->getCookieName();
    }

    public function publish(Update $update): string
    {
        // The test suite never talks to a hub. Updates are kept in memory so a
        // test can assert what the application broadcast.
        if ('test' === $this->environment) {
            $this->publishedUpdates[] = $update;

            return 'test://'.($update->getId() ?? '');
        }

        if (\function_exists('mercure_publish')) {
            return $this->inner->publish($update);
        }

        return $this->httpHub()->publish($update);
    }

    /**
     * Updates published since the last reset. Only populated in the test environment.
     *
     * @return list<Update>
     */
    public function getPublishedUpdates(): array
    {
        return $this->publishedUpdates;
    }

    public function resetPublishedUpdates(): void
    {
        $this->publishedUpdates = [];
    }

    /**
     * Fallback used when the code runs outside FrankenPHP, for example during
     * local development with the PHP built-in server.
     */
    private function httpHub(): Hub
    {
        if (null === $this->httpHub) {
            $factory = $this->inner->getFactory();

            if (null === $factory) {
                throw new \LogicException('The Mercure hub has no JWT factory, so the HTTP fallback cannot sign a publisher token. Configure mercure.hubs.default.jwt.secret.');
            }

            $this->httpHub = new Hub(
                url: $this->mercureUrl,
                jwtProvider: new FactoryTokenProvider($factory),
                jwtFactory: $factory,
                publicUrl: $this->inner->getPublicUrl(),
                httpClient: $this->httpClient,
                cookieName: $this->inner->getCookieName(),
                protocolVersion: $this->inner->getProtocolVersion(),
            );
        }

        return $this->httpHub;
    }
}
