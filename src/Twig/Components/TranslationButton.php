<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Translation\Translations;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The button that opens the list of translations of a field hint.
 *
 * It sits beside a field so a reader who does not read English can still tell
 * what the field expects. The hint it shows is picked by its key.
 */
#[AsTwigComponent]
final class TranslationButton
{
    /**
     * @var array<string, string>
     */
    public array $translations = [];

    public function mount(string $key): void
    {
        $this->translations = Translations::for($key);
    }
}
