<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Sets the content type of a response from the format its route declares.
 *
 * A route that renders something other than HTML, such as the QR code route
 * which answers with an SVG document, declares it with a _format default. A
 * controller that answers with a Turbo Stream marks the request the same way
 * before it renders. The formats themselves are mapped in
 * config/packages/framework.yaml.
 *
 * The format is read from the route rather than from the Accept header. A
 * browser lists "image/svg+xml" among the types it accepts when it fetches a
 * picture, and a negotiated format would then label the png of a logo as an
 * SVG document, which the browser refuses to display. The same goes for Turbo:
 * it announces that it accepts a stream on every form post, including the ones
 * whose answer is a page it will follow to. Reading that announcement as a
 * stream would label the page itself as a stream, and Turbo would then render
 * the page as an empty stream instead of showing it.
 */
#[AsEventListener(event: ResponseEvent::class, priority: -10)]
final class FormatContentTypeListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $format = $request->getRequestFormat(null);

        if (null === $format || 'html' === $format) {
            return;
        }

        $mimeType = $request->getMimeType($format);

        if (null !== $mimeType) {
            $event->getResponse()->headers->set('Content-Type', $mimeType);
        }
    }
}
