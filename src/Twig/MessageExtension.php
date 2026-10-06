<?php

declare(strict_types=1);

namespace App\Twig;

use App\ActiveStorage\Attachments;
use App\Entity\ActiveStorageBlob;
use App\Entity\Boost;
use App\Entity\Message;
use App\Message\MessageBody;
use App\Repository\BoostRepository;
use App\Sound\Sound;
use App\Sound\SoundLibrary;
use Twig\Attribute\AsTwigFunction;

/**
 * The parts of a message that are not its body.
 *
 * A message carries at most one file, and a message with a file shows the file
 * rather than its text, which is what the original application decides when it
 * picks a presentation for a message.
 */
final class MessageExtension
{
    public const TYPE_ATTACHMENT = 'attachment';
    public const TYPE_SOUND = 'sound';
    public const TYPE_TEXT = 'text';

    /**
     * A message that is only this is a request to play the named sound.
     */
    private const SOUND_PATTERN = '/\A\/play (?<name>\w+)\z/';

    public function __construct(
        private readonly Attachments $attachments,
        private readonly MessageBody $body,
        private readonly BoostRepository $boosts,
        private readonly SoundLibrary $sounds,
    ) {
    }

    /**
     * The boosts of a message, oldest first. A boost is shown under the message
     * it reacts to, so the message template asks for them itself.
     *
     * @return list<Boost>
     */
    #[AsTwigFunction('message_boosts')]
    public function boosts(Message $message): array
    {
        return $this->boosts->findOrderedFor($message);
    }

    #[AsTwigFunction('message_attachment')]
    public function attachment(Message $message): ?ActiveStorageBlob
    {
        return $this->attachments->blobFor($message, Attachments::ATTACHMENT);
    }

    /**
     * The sound a message asks for, when its whole text is a request to play
     * one. A name that is not in the library is not a sound, so the message
     * stays text and the request is shown as it was written.
     */
    #[AsTwigFunction('message_sound')]
    public function sound(Message $message): ?Sound
    {
        $matched = preg_match(self::SOUND_PATTERN, $this->plainText($message), $matches);

        return 1 === $matched ? $this->sounds->find($matches['name']) : null;
    }

    #[AsTwigFunction('message_content_type')]
    public function contentType(Message $message): string
    {
        if (null !== $this->attachment($message)) {
            return self::TYPE_ATTACHMENT;
        }

        return null === $this->sound($message) ? self::TYPE_TEXT : self::TYPE_SOUND;
    }

    /**
     * What a notification or a bot payload shows for a message: its text, and
     * the name of its file when it has no text at all.
     */
    #[AsTwigFunction('message_plain_text')]
    public function plainText(Message $message): string
    {
        return $this->body->plainText($message);
    }

    /**
     * Whether the message is nothing but emoji, which is shown larger.
     */
    #[AsTwigFunction('message_all_emoji')]
    public function allEmoji(Message $message): bool
    {
        return $this->body->allEmoji($message);
    }
}
