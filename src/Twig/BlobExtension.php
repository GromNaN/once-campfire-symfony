<?php

declare(strict_types=1);

namespace App\Twig;

use App\ActionText\SignedId;
use App\Entity\ActiveStorageBlob;
use App\Rails\RailsModelName;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Builds the addresses of uploaded files.
 *
 * A file is addressed by a signed identifier rather than by its row, so the
 * address cannot be guessed and the identifier carries the only right a reader
 * needs. The name of the file is kept at the end of the address, the way the
 * original application writes it, which lets a browser save it under the name
 * its author gave.
 */
final class BlobExtension
{
    /**
     * Rails counts in powers of 1024 and names the units after the decimal
     * ones, which is what the original shows next to a file.
     */
    private const UNITS = ['KB', 'MB', 'GB', 'TB', 'PB', 'EB'];

    public function __construct(
        private readonly SignedId $signedIds,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsTwigFunction('blob_path')]
    public function path(ActiveStorageBlob $blob, ?string $variant = null, ?string $disposition = null): string
    {
        $parameters = [
            'signed_id' => $this->signedIds->encode(RailsModelName::BLOB, (int) $blob->getId(), SignedId::PURPOSE_BLOB),
            'filename' => $blob->getFilename(),
        ];

        if (null !== $variant) {
            $parameters['variant'] = $variant;
        }

        if (null !== $disposition) {
            $parameters['disposition'] = $disposition;
        }

        return $this->urlGenerator->generate('rails_blob', $parameters);
    }

    #[AsTwigFunction('human_size')]
    public function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return \sprintf('%d %s', $bytes, 1 === $bytes ? 'Byte' : 'Bytes');
        }

        $number = $bytes / 1024;
        $unit = 0;

        while ($number >= 1024 && isset(self::UNITS[$unit + 1])) {
            $number /= 1024;
            ++$unit;
        }

        return \sprintf('%s %s', self::significant($number), self::UNITS[$unit]);
    }

    /**
     * Three significant digits, with the insignificant zeros dropped, which is
     * how Rails rounds a file size.
     */
    private static function significant(float $number): string
    {
        $digits = max(0, 2 - (int) floor(log10($number)));
        $formatted = number_format($number, $digits, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }
}
