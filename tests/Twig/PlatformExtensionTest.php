<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Http\Platform;
use App\Twig\PlatformExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The platform is read from the user agent of the request being answered.
 *
 * The service is shared across requests under worker mode, so a later visitor
 * must not be told the browser of the first one.
 */
final class PlatformExtensionTest extends TestCase
{
    public function testEachRequestIsReadFromItsOwnUserAgent(): void
    {
        $requests = new RequestStack();
        $extension = new PlatformExtension($requests);

        $requests->push(self::requestWithUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Version/17.0 Safari/605.1.15'));
        self::assertSame(Platform::SAFARI, $extension->platform()->browser);

        $requests->push(self::requestWithUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'));
        self::assertSame(Platform::CHROME, $extension->platform()->browser);

        $requests->pop();
        self::assertSame(Platform::SAFARI, $extension->platform()->browser);
    }

    public function testTheSameUserAgentIsOnlyWorkedOutOnce(): void
    {
        $requests = new RequestStack();
        $extension = new PlatformExtension($requests);

        $requests->push(self::requestWithUserAgent('Mozilla/5.0 (X11; Linux x86_64) Firefox/121.0'));

        self::assertSame($extension->platform(), $extension->platform());
    }

    private static function requestWithUserAgent(string $userAgent): Request
    {
        return Request::create('/', server: ['HTTP_USER_AGENT' => $userAgent]);
    }
}
