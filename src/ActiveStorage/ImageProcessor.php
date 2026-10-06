<?php

declare(strict_types=1);

namespace App\ActiveStorage;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Reads the size of an image and shrinks it to fit a box.
 *
 * Both operations go through the same library so the metadata stored with a
 * blob and the variant made from it agree. A file that is not a readable image
 * is reported as such rather than throwing, because uploads are user input.
 */
final class ImageProcessor
{
    public const FORMAT_WEBP = 'webp';
    public const FORMAT_PNG = 'png';

    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(self::driver());
    }

    /**
     * Width and height of an image, or null when the bytes are not an image the
     * driver understands.
     *
     * @return array{width: int, height: int}|null
     */
    public function size(string $bytes): ?array
    {
        $image = $this->read($bytes);

        if (null === $image) {
            return null;
        }

        return ['width' => $image->width(), 'height' => $image->height()];
    }

    /**
     * Shrinks an image so that it fits inside the box, keeping its proportions,
     * and encodes it in the given format. An image already smaller than the box
     * is only re-encoded, which is what resize_to_limit does in Rails.
     */
    public function scaleDown(string $bytes, int $width, int $height, string $format, int $quality = 90): ?string
    {
        $image = $this->read($bytes);

        if (null === $image) {
            return null;
        }

        return (string) $image->scaleDown(width: $width, height: $height)->encode($this->encoder($format, $quality));
    }

    public function mediaType(string $format): string
    {
        return match ($format) {
            self::FORMAT_WEBP => 'image/webp',
            self::FORMAT_PNG => 'image/png',
            default => throw new \InvalidArgumentException(\sprintf('Unsupported image format "%s".', $format)),
        };
    }

    /**
     * Encoders are built here so the supported formats stay in one place.
     */
    public function encoder(string $format, int $quality = 90): EncoderInterface
    {
        return match ($format) {
            self::FORMAT_WEBP => new WebpEncoder(quality: $quality),
            self::FORMAT_PNG => new PngEncoder(),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported image format "%s".', $format)),
        };
    }

    /**
     * Imagick handles more formats and is faster, GD is what the Docker image
     * ships with. Both are acceptable, so the best one present is used.
     */
    private static function driver(): DriverInterface
    {
        if (\extension_loaded('imagick')) {
            return new ImagickDriver();
        }

        if (\extension_loaded('gd')) {
            return new GdDriver();
        }

        throw new \RuntimeException('Image processing needs the gd or imagick extension.');
    }

    private function read(string $bytes): ?ImageInterface
    {
        try {
            return $this->manager->decode($bytes);
        } catch (\Throwable) {
            return null;
        }
    }
}
