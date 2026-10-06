<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\TurboStream;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Sets the content type of a response from the format its route declares.
 *
 * A route that renders something other than HTML, such as the QR code route
 * which answers with an SVG document, declares it with a _format default. The
 * formats themselves are mapped in config/packages/framework.yaml.
 *
 * The format is read from the route rather than from the Accept header. A
 * browser lists "image/svg+xml" among the types it accepts when it fetches a
 * picture, and a negotiated format would then label the png of a logo as an
 * SVG document, which the browser refuses to display.
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

        // The one format a client negotiates: Turbo announces it in the Accept
        // header of the requests it takes over, and no route declares it.
        if (null === $format && TurboStream::wants($request)) {
            $format = TurboBundle::STREAM_FORMAT;
        }

        if (null === $format || 'html' === $format) {
            return;
        }

        $mimeType = $request->getMimeType($format);

        if (null !== $mimeType) {
            $event->getResponse()->headers->set('Content-Type', $mimeType);
        }
    }
}
