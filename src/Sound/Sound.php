<?php

declare(strict_types=1);

namespace App\Sound;

/**
 * A sound a message can play.
 *
 * A reader types "/play <name>" as the whole text of a message, and the message
 * is shown as the sound instead of the text. The sound carries what is shown
 * beside the play button: either a picture, for the sounds that are a meme, or
 * a line of text for the ones that are only a joke written out.
 */
final readonly class Sound
{
    private function __construct(
        public string $name,
        public ?string $text = null,
        public ?SoundImage $image = null,
    ) {
    }

    public static function withText(string $name, string $text): self
    {
        return new self($name, $text);
    }

    public static function withImage(string $name, string $image, int $width, int $height): self
    {
        return new self($name, null, new SoundImage($image, $width, $height));
    }

    /**
     * The audio file, relative to the assets directory.
     */
    public function audioPath(): string
    {
        return \sprintf('sounds/%s.mp3', $this->name);
    }
}
