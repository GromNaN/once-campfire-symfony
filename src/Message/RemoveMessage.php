<?php

declare(strict_types=1);

namespace App\Message;

use App\ActionText\RichTextRepository;
use App\ActiveStorage\Attachments;
use App\Entity\Message;
use App\Mercure\RoomBroadcast;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a message and everything that hung off it.
 *
 * The body lives in another table and the file on disk, so neither goes away
 * with the row. The full text index is not touched here: a listener on the
 * entity takes the row out of it whenever a message is deleted, whichever way
 * it is deleted.
 */
final class RemoveMessage
{
    public function __construct(
        private readonly RichTextRepository $richTexts,
        private readonly Attachments $attachments,
        private readonly EntityManagerInterface $entityManager,
        private readonly RoomBroadcast $broadcast,
    ) {
    }

    public function remove(Message $message): void
    {
        $this->richTexts->remove((int) $message->getId());
        $this->attachments->purge($message, Attachments::ATTACHMENT);

        $this->entityManager->remove($message);
        $this->entityManager->flush();

        $this->broadcast->messageRemoved($message);
    }
}
