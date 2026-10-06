<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\Browser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;

/**
 * Shows an upgrade page instead of the application to browsers that are too
 * old, with the same minimum versions as the original application.
 *
 * Only controller requests are gated, so that stylesheets and scripts still
 * load on the upgrade page itself.
 */
#[AsEventListener(event: RequestEvent::class, priority: 1)]
final class BrowserGateListener
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isControllerRequest($event)) {
            return;
        }

        $browser = Browser::fromUserAgent($event->getRequest()->headers->get('User-Agent'));

        if (!$this->isBlocked($browser)) {
            return;
        }

        $event->setResponse(
            new Response($this->twig->render('sessions/incompatible_browser.html.twig')),
        );
    }

    /**
     * Internal routes, such as the profiler or an asset served by the asset
     * mapper, are never gated.
     */
    private function isControllerRequest(RequestEvent $event): bool
    {
        $route = $event->getRequest()->attributes->get('_route');

        return \is_string($route) && '' !== $route && !str_starts_with($route, '_');
    }

    private function isBlocked(Browser $browser): bool
    {
        if (!\array_key_exists($browser->name, Browser::MINIMUM_VERSIONS)) {
            return false;
        }

        $minimum = Browser::MINIMUM_VERSIONS[$browser->name];

        if (false === $minimum) {
            return true;
        }

        return null === $browser->version || $browser->version < $minimum;
    }
}
