<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Holds the fields of the message composer.
 */
final class MessageData
{
    /**
     * The rich text body, as HTML.
     */
    public ?string $body = null;

    /**
     * The file the message carries, when one was picked.
     */
    public ?UploadedFile $attachment = null;

    /**
     * Identifier chosen by the client, so that the message the browser shows
     * straight away can be replaced by the saved one.
     */
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $clientMessageId = null;

    /**
     * An empty paragraph is not a message, so the text is what decides. A file
     * on its own is a message, and the original application accepts one.
     */
    #[Assert\Callback]
    public function validateBody(ExecutionContextInterface $context): void
    {
        if (null !== $this->attachment) {
            return;
        }

        $text = html_entity_decode(strip_tags($this->body ?? ''), \ENT_QUOTES | \ENT_HTML5);

        if ('' === trim($text)) {
            $context->buildViolation('Write a message.')->atPath('body')->addViolation();
        }
    }
}
