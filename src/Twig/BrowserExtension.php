<?php

declare(strict_types=1);

namespace App\Twig;

use App\Http\Browser;
use Twig\Attribute\AsTwigFunction;

/**
 * Tells the upgrade page which browsers it should suggest.
 *
 * The list lives with the code that decides which browsers are refused, so the
 * page and the gate can never drift apart.
 */
final class BrowserExtension
{
    /**
     * @return array<string, float>
     */
    #[AsTwigFunction('supported_browsers')]
    public function supportedBrowsers(): array
    {
        return Browser::supportedVersions();
    }
}
