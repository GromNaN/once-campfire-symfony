<?php

declare(strict_types=1);

namespace App\Message;

use App\ActionText\RichTextRepository;
use App\Bot\WebhookDispatcher;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use App\Mercure\RoomBroadcast;
use App\Search\MessageIndex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Writes a message: its row, what it says, and everything that follows.
 *
 * The row is written first and its body right after, because the body lives in
 * another table: the message exists before it has anything to say. The text
 * index and the browsers watching the room are only told once the body and the
 * file are there. A member writing in the composer and a bot posting over the
 * JSON API go through the same steps.
 */
final class MessageWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RichTextRepository $richTexts,
        private readonly MessageFiles $files,
        private readonly MessageIndex $index,
        private readonly RoomBroadcast $broadcast,
        private readonly WebhookDispatcher $webhooks,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function create(
        Room $room,
        User $creator,
        ?string $body = null,
        ?UploadedFile $file = null,
        ?string $clientMessageId = null,
    ): Message {
        $message = new Message();
        $message->setRoom($room);
        $message->setCreator($creator);

        if (null !== $clientMessageId && '' !== $clientMessageId) {
            $message->setClientMessageId($clientMessageId);
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->write($message, $body, $file);
        $this->index->index($message);
        $this->broadcast->messageCreated($message);
        $this->webhooks->dispatchFor($message);

        // The notification is sent by a worker, so the badge it carries counts
        // the rooms the reader has not caught up with by then.
        $this->bus->dispatch(new SendPushNotification((int) $message->getId()));

        return $message;
    }

    /**
     * Changes what a message says, and tells the room about it.
     *
     * A message that has been posted is never announced to the bots again: an
     * edit is not a new message.
     */
    public function update(Message $message, ?string $body = null, ?UploadedFile $file = null): void
    {
        $message->touch();

        $this->write($message, $body, $file);
        $this->index->index($message);
        $this->broadcast->messageUpdated($message);
    }

    /**
     * A message shows its body or its file, never both, so a file that arrives
     * takes the place of the body. The body stays written down, as it does in
     * the original application, where putting the file away brings it back.
     */
    private function write(Message $message, ?string $body, ?UploadedFile $file): void
    {
        if (null === $file) {
            $this->richTexts->setBody((int) $message->getId(), $body ?? '');

            return;
        }

        $this->files->attachUpload($message, $file);
    }
}
