<?php

declare(strict_types=1);

namespace App\Message;

use App\ActionText\AttachableRenderer;
use App\ActionText\RichTextRepository;
use App\ActiveStorage\Attachments;
use App\Entity\Message;

/**
 * Reads the body of a message in the form a caller needs.
 *
 * The body is stored as the HTML the editor wrote, with the mentions and the
 * link previews still kept as attachment elements. The three forms here are the
 * ones the application uses: the markup a page shows, the stored form an editor
 * round-trips, and the plain text a notification, a search index or a bot
 * payload carries.
 */
final class MessageBody
{
    /**
     * A body that is nothing but emoji. The stylesheets show such a message
     * larger, because one emoji is a reaction rather than a sentence.
     */
    private const ALL_EMOJI = '/\A(\p{Emoji_Presentation}|\p{Extended_Pictographic}|\x{FE0F})+\z/u';

    public function __construct(
        private readonly RichTextRepository $richTexts,
        private readonly AttachableRenderer $renderer,
        private readonly Attachments $attachments,
    ) {
    }

    /**
     * The markup of the body, with the mentions and the previews drawn.
     *
     * It is wrapped in the container the rich text styles are written for,
     * which is what makes a list, a quote, a code block, a link preview or a
     * message made of a single emoji look the way the original does.
     */
    public function html(Message $message): string
    {
        return '<div class="lexxy-content">'.$this->renderer->render($this->stored($message)).'</div>';
    }

    /**
     * Whether the text of the message is nothing but emoji.
     */
    public function allEmoji(Message $message): bool
    {
        return 1 === preg_match(self::ALL_EMOJI, $this->plainText($message));
    }

    /**
     * The stored body, with the attachment elements left in place.
     */
    public function stored(Message $message): string
    {
        return $this->richTexts->bodyFor((int) $message->getId());
    }

    /**
     * The text of a message: its body as text, and the name of its file when
     * the body carries no text at all. This is what the original application
     * calls the plain text body.
     */
    public function plainText(Message $message): string
    {
        $text = $this->renderer->plainText($this->stored($message));

        if ('' !== trim($text)) {
            return $text;
        }

        return $this->attachments->blobFor($message, Attachments::ATTACHMENT)?->getFilename() ?? '';
    }
}
