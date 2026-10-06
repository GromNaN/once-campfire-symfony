<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RemoveBannedContent;
use App\Message\RemoveMessage;
use App\Repository\MessageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes the words of a banned member out of the rooms they were in.
 *
 * The messages are read again here rather than carried in the envelope,
 * because the ban is answered before this runs and the room has moved on since.
 * Each removal is broadcast, so the message disappears from the browsers
 * watching the room and not only from the database.
 */
#[AsMessageHandler]
final class RemoveBannedContentHandler
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly RemoveMessage $remove,
    ) {
    }

    public function __invoke(RemoveBannedContent $message): void
    {
        foreach ($this->messages->findByCreatorId($message->userId) as $posted) {
            $this->remove->remove($posted);
        }
    }
}
