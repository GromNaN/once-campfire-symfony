<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\TurboStream;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\WebLink\HttpHeaderSerializer;
use Symfony\Component\WebLink\Link;

/**
 * Preloads the assets of a page before the page itself is ready.
 *
 * A full page load answers with an HTTP 103 Early Hints response carrying the
 * stylesheet, the JavaScript entrypoint and the Mercure hub, so the browser
 * starts fetching them while the application is still rendering the room. The
 * final response is unchanged: 103 only carries links, and a server that does
 * not support it drops it.
 *
 * Fragments are left out on purpose. A Turbo Frame or a Turbo Stream answers a
 * navigation the browser already paid for, so there is nothing to warm up.
 */
#[AsEventListener(event: RequestEvent::class, priority: 0)]
final class EarlyHintsListener
{
    public function __construct(
        private readonly AssetMapperInterface $assetMapper,
        private readonly HttpHeaderSerializer $serializer,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $mercurePublicUrl,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->hintsFor($event->getRequest())?->sendHeaders(Response::HTTP_EARLY_HINTS);
    }

    /**
     * The 103 response for a request, or null when the request is not a page
     * load and there is nothing to preload.
     */
    public function hintsFor(Request $request): ?Response
    {
        if (!$this->isPageLoad($request)) {
            return null;
        }

        $links = [
            (new Link('preload', $this->assetMapper->getPublicPath('styles/app.css')))->withAttribute('as', 'style'),
            (new Link('preload', $this->assetMapper->getPublicPath('app.js')))->withAttribute('as', 'script'),
        ];

        if ('' !== $origin = $this->mercureOrigin()) {
            $links[] = new Link('preconnect', $origin);
        }

        $response = new Response('', Response::HTTP_EARLY_HINTS);
        $response->headers->set('Link', (string) $this->serializer->serialize($links), false);

        return $response;
    }

    private function isPageLoad(Request $request): bool
    {
        if (!$request->isMethodSafe() || $request->isXmlHttpRequest()) {
            return false;
        }

        // A Turbo Frame request answers a navigation the browser has already
        // started, and a Turbo Stream request is not a page at all.
        if ($request->headers->has('Turbo-Frame') || TurboStream::wants($request)) {
            return false;
        }

        $route = $request->attributes->get('_route');

        if (!\is_string($route) || str_starts_with($route, '_')) {
            return false;
        }

        return 'html' === $request->getPreferredFormat();
    }

    /**
     * The hub is usually served from the same origin, in which case there is
     * nothing to connect to early.
     */
    private function mercureOrigin(): string
    {
        $parts = parse_url($this->mercurePublicUrl);

        if (!\is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$parts['host'].$port;
    }
}
