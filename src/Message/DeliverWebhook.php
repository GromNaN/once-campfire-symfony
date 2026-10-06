<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks the worker to announce a message to a bot.
 *
 * Delivery happens outside the request cycle because a slow or broken endpoint
 * must never delay a message being posted. The worker reads both records again
 * when it runs, so a message edited in the meantime is announced as it is now.
 */
final readonly class DeliverWebhook
{
    public function __construct(
        public int $webhookId,
        public int $messageId,
    ) {
    }
}
