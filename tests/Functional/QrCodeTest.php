<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Covers the QR code page, which turns the identifier of a share link back
 * into a scannable SVG document.
 */
final class QrCodeTest extends DatabaseTestCase
{
    public function testThePageAnswersWithASvgDocument(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/qr_code/'.$this->encode('https://example.com/join/ABCD-EFGH-IJKL'));

        self::assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        self::assertStringStartsWith('image/svg+xml', (string) $response->headers->get('Content-Type'));

        // The document has to reach the browser as markup, not as escaped text.
        $content = (string) $response->getContent();

        self::assertStringContainsString('<svg', $content);
        self::assertStringNotContainsString('&lt;svg', $content);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
