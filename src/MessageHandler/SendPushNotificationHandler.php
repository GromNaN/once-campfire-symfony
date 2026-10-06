<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendPushNotification;
use App\Push\PushNotifier;
use App\Repository\MessageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tells the readers who are away that a room has something new.
 *
 * The message is read again here rather than carried in the envelope: the
 * notification is sent after the request that posted the message, so what it
 * says is what the room holds when the worker runs. A message deleted in the
 * meantime has nothing to announce.
 */
#[AsMessageHandler]
final class SendPushNotificationHandler
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly PushNotifier $notifier,
    ) {
    }

    public function __invoke(SendPushNotification $message): void
    {
        $posted = $this->messages->find($message->messageId);

        if (null === $posted) {
            return;
        }

        $this->notifier->notifyFor($posted);
    }
}
