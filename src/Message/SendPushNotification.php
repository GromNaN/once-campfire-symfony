<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to fan a new message out to the push subscriptions of the
 * members who asked to be notified.
 */
final readonly class SendPushNotification
{
    public function __construct(public int $messageId)
    {
    }
}
