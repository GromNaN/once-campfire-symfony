<?php

declare(strict_types=1);

namespace App\Twig;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Draws the QR code of a value as an SVG document.
 *
 * The identifier in the URL is the urlsafe base64 encoding of the value to
 * encode, which is what the original application puts in its share links.
 */
final class QrCodeExtension
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * The address of the QR code of a value, which a page links to so the code
     * opens on its own and can be scanned from a phone.
     */
    #[AsTwigFunction('qr_code_path')]
    public function path(string $value): string
    {
        return $this->urlGenerator->generate('qr_code', ['id' => $this->encode($value)]);
    }

    #[AsTwigFunction('qr_code_svg', isSafe: ['html'])]
    public function svg(string $encoded): string
    {
        $value = $this->decode($encoded);

        return (new SvgWriter())->write(new QrCode($value, size: 300, margin: 0))->getString();
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $decoded ? '' : $decoded;
    }
}
