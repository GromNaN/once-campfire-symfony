<?php

declare(strict_types=1);

namespace App\ActiveStorage;

use Symfony\Component\Mime\MimeTypes;

/**
 * The images shipped with the application.
 *
 * They are served from the controllers that fall back to them, such as the
 * avatar of a bot or the logo of an account that has not uploaded one, so the
 * response is the image itself and not a redirect.
 */
final class StockImages
{
    public const BOT_AVATAR = 'default-bot-avatar.svg';
    public const APP_ICON = 'logos/app-icon.png';
    public const APP_ICON_SMALL = 'logos/app-icon-192.png';

    public function __construct(private readonly string $imagesDir)
    {
    }

    public function path(string $name): string
    {
        return $this->imagesDir.\DIRECTORY_SEPARATOR.$name;
    }

    public function contents(string $name): string
    {
        $contents = @file_get_contents($this->path($name));

        if (false === $contents) {
            throw new \RuntimeException(\sprintf('The image "%s" is missing from %s.', $name, $this->imagesDir));
        }

        return $contents;
    }

    public function contentType(string $name): string
    {
        return MimeTypes::getDefault()->guessMimeType($this->path($name)) ?? 'application/octet-stream';
    }
}
