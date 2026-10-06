<?php

declare(strict_types=1);

namespace App\Twig;

use App\Translation\Translations;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * The button that opens the list of translations of a field hint.
 *
 * The markup lives in a template of its own, because it is a small page
 * fragment rather than a value, and it is rendered here so a template can ask
 * for it with the name of the hint it belongs to.
 */
final class TranslationExtension
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @return array<string, string>
     */
    #[AsTwigFunction('translations_for')]
    public function translationsFor(string $key): array
    {
        return Translations::for($key);
    }

    #[AsTwigFunction('translation_button', isSafe: ['html'])]
    public function button(string $key): string
    {
        return $this->twig->render('layouts/_translation_button.html.twig', [
            'translations' => Translations::for($key),
        ]);
    }
}
