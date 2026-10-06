<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\Message;
use App\Entity\Room;
use App\Repository\MessageRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads the page of messages a caller asked for.
 *
 * A page is reached by walking from a message rather than by an offset: before
 * returns the page older than the given message, after the page newer than it,
 * and neither returns the newest page. A browser reading a room and a bot
 * reading it over the JSON API ask the same question, so they share this.
 *
 * A cursor that names no message of the room is an error rather than a reason
 * to fall back to the last page: the original application walks from a message
 * it looks up, which raises when the message is not there.
 */
final class MessagePage
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessageBody $body,
    ) {
    }

    /**
     * @return list<Message>
     */
    public function load(Room $room, string $before, string $after): array
    {
        $messages = $this->pageFor($room, $before, $after);

        // A page shows a whole page of messages, so their bodies and their
        // files are read in two queries before the templates ask for them.
        $this->body->prime($messages);

        return $messages;
    }

    /**
     * @return list<Message>
     */
    private function pageFor(Room $room, string $before, string $after): array
    {
        if ('' !== $before) {
            return $this->messages->findPageBefore($room, $this->cursor($room, $before));
        }

        if ('' !== $after) {
            return $this->messages->findPageAfter($room, $this->cursor($room, $after));
        }

        return $this->messages->findLastPage($room);
    }

    /**
     * The message a cursor names, or a 404 when it names none of the room.
     */
    private function cursor(Room $room, string $cursor): Message
    {
        $message = $this->messages->findInRoom($room, (int) $cursor);

        if (null === $message) {
            throw new NotFoundHttpException(sprintf('Message %s not found in room %s.', $cursor, $room->getId()));
        }

        return $message;
    }
}
