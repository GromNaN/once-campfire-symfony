<?php

declare(strict_types=1);

namespace App\Sound;

/**
 * The picture shown beside the play button of a sound.
 *
 * The width and the height are declared by the sound rather than read from the
 * file, so the message does not jump while the picture loads.
 */
final readonly class SoundImage
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * The picture, relative to the assets directory.
     */
    public function path(): string
    {
        return \sprintf('sounds/%s', $this->name);
    }
}
