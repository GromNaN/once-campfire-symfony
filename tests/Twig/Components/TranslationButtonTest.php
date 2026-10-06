<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The translation button is a component, so a template asks for it by name
 * rather than through a function that renders a template of its own.
 */
final class TranslationButtonTest extends KernelTestCase
{
    public function testItRendersEveryTranslationOfAFieldHint(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig
            ->createTemplate("{{ component('TranslationButton', { key: 'user_name' }) }}")
            ->render();

        self::assertStringContainsString('data-controller="popup"', $html);
        self::assertStringContainsString('Enter your name', $html);
        self::assertStringContainsString('Entrez votre nom', $html);
    }

    public function testAnUnknownKeyRendersAnEmptyList(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig
            ->createTemplate("{{ component('TranslationButton', { key: 'not_a_field' }) }}")
            ->render();

        self::assertStringContainsString('language-list', $html);
        self::assertStringNotContainsString('<dt>', $html);
    }
}
