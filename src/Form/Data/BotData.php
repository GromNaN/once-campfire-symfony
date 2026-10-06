<?php

declare(strict_types=1);

namespace App\Form\Data;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Holds the fields of a chat bot: what it is called, what it looks like, and
 * the address it is told through.
 */
final class BotData
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 255, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $name = null;

    #[Assert\Image(maxSize: '8M', maxSizeMessage: 'Upload a picture smaller than {{ limit }}.')]
    public ?UploadedFile $avatar = null;

    /**
     * Left empty, the bot keeps no webhook and is only reachable through the
     * API. Clearing a webhook that exists removes it.
     */
    #[Assert\Url(message: 'Enter a web address.', requireTld: false)]
    #[Assert\Length(max: 2048, maxMessage: 'Use at most {{ limit }} characters.')]
    public ?string $webhookUrl = null;
}
