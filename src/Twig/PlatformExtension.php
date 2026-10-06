<?php

declare(strict_types=1);

namespace App\Twig;

use App\Http\Platform;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Tells a page what the browser reading it runs on.
 *
 * The help shown when notifications are refused names the browser and the
 * system of the reader, because the steps to allow them are different for
 * each. The platform is worked out from the user agent of the request being
 * answered, so the last one is kept only while the same user agent is read
 * again, which is what happens several times in one page.
 */
final class PlatformExtension
{
    private ?string $cachedUserAgent = null;

    private ?Platform $cachedPlatform = null;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[AsTwigFunction('platform')]
    public function platform(): Platform
    {
        $userAgent = $this->requests->getCurrentRequest()?->headers->get('User-Agent');

        // A service outlives the request that used it under worker mode, so the
        // cache is keyed by the user agent rather than kept as one platform:
        // reusing the first visitor's browser for every later one is wrong.
        if (null === $this->cachedPlatform || $this->cachedUserAgent !== $userAgent) {
            $this->cachedPlatform = Platform::fromUserAgent($userAgent);
            $this->cachedUserAgent = $userAgent;
        }

        return $this->cachedPlatform;
    }
}
