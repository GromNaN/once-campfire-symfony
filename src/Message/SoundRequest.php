<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\Message;
use App\Sound\Sound;
use App\Sound\SoundLibrary;

/**
 * The request to play a sound that a message can be.
 *
 * A message whose whole text is "/play <name>" asks for the named sound. A name
 * that is not in the library is not a sound, so the message stays text and the
 * request is shown as it was written.
 */
final class SoundRequest
{
    /**
     * A message that is only this is a request to play the named sound.
     */
    private const PATTERN = '/\A\/play (?<name>\w+)\z/';

    public function __construct(
        private readonly MessageBody $body,
        private readonly SoundLibrary $sounds,
    ) {
    }

    public function forMessage(Message $message): ?Sound
    {
        $matched = preg_match(self::PATTERN, $this->body->plainText($message), $matches);

        return 1 === $matched ? $this->sounds->find($matches['name']) : null;
    }
}
