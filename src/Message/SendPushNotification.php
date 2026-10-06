<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Asks the worker to fan a new message out to the push subscriptions of the
 * members who asked to be notified.
 */
#[AsMessage(transport: 'async')]
final readonly class SendPushNotification
{
    public function __construct(public int $messageId)
    {
    }
}
