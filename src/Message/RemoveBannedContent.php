<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Asks the worker to delete the messages, boosts and attachments a user left
 * behind after being banned.
 */
#[AsMessage(transport: 'async')]
final readonly class RemoveBannedContent
{
    public function __construct(public int $userId)
    {
    }
}
