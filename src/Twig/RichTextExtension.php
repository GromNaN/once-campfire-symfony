<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Message;
use App\Message\MessageBody;
use Twig\Attribute\AsTwigFunction;

/**
 * Renders the rich text body of a message.
 *
 * What is stored is the sanitized HTML the editor wrote, with the mentions and
 * the link previews still kept as attachment elements. Rendering them is what
 * this extension does, so the stored form stays the one the original
 * application writes and reads.
 */
final class RichTextExtension
{
    public function __construct(private readonly MessageBody $body)
    {
    }

    #[AsTwigFunction('message_body', isSafe: ['html'])]
    public function body(Message $message): string
    {
        return $this->body->html($message);
    }

    /**
     * The stored body, with the attachment elements left in place. Editing a
     * message starts from this form, which is the one the editor round-trips.
     */
    #[AsTwigFunction('message_editable_body', isSafe: ['html'])]
    public function editableBody(Message $message): string
    {
        return $this->body->stored($message);
    }

    /**
     * The text form of a body, which is what a bot payload or a notification
     * carries.
     */
    #[AsTwigFunction('message_body_text')]
    public function text(Message $message): string
    {
        return $this->body->plainText($message);
    }
}
