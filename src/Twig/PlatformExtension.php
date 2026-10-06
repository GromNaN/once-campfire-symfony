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
 * each. Reading the user agent once per request is enough.
 */
final class PlatformExtension
{
    private ?Platform $platform = null;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[AsTwigFunction('platform')]
    public function platform(): Platform
    {
        return $this->platform ??= Platform::fromUserAgent(
            $this->requests->getCurrentRequest()?->headers->get('User-Agent'),
        );
    }
}
