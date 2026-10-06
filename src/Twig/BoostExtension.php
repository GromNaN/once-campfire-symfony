<?php

declare(strict_types=1);

namespace App\Twig;

use App\Boost\Reactions;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the one click reactions to the templates.
 */
final class BoostExtension
{
    /**
     * @return array<string, string> the character, and what it is called
     */
    #[AsTwigFunction('boost_reactions')]
    public function reactions(): array
    {
        return Reactions::ALL;
    }
}
