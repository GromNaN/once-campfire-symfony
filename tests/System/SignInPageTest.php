<?php

declare(strict_types=1);

namespace App\Tests\System;

use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Walks a page in a real browser, which is the only way to know that the
 * JavaScript the layout carries runs: the functional tests read the HTML, they
 * do not execute it. The sign in page is the one page that needs no data, so it
 * is the cheapest place to catch a script that fails to boot.
 *
 * A Chrome binary is required. Where there is none the test is skipped rather
 * than failed, so a machine without a browser can still run the suite.
 */
final class SignInPageTest extends PantherTestCase
{
    public function testTheSignInPageRendersInABrowser(): void
    {
        if (!self::browserAvailable()) {
            self::markTestSkipped('A Chrome binary is required to run the browser test.');
        }

        $client = self::createPantherClient();
        $client->request('GET', '/session/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    private static function browserAvailable(): bool
    {
        $configured = getenv('PANTHER_CHROME_BINARY');

        if (\is_string($configured) && '' !== $configured) {
            return true;
        }

        $finder = new ExecutableFinder();

        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome'] as $binary) {
            if (null !== $finder->find($binary)) {
                return true;
            }
        }

        return false;
    }
}
