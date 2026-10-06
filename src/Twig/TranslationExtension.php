<?php

declare(strict_types=1);

namespace App\Twig;

use App\Translation\Translations;
use Twig\Attribute\AsTwigFunction;

/**
 * The translations of a field hint.
 *
 * The button that opens the list is a Twig component, so this extension only
 * hands the list itself to templates that want to read it directly.
 */
final class TranslationExtension
{
    /**
     * @return array<string, string>
     */
    #[AsTwigFunction('translations_for')]
    public function translationsFor(string $key): array
    {
        return Translations::for($key);
    }
}
