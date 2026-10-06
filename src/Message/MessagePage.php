<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\Message;
use App\Entity\Room;
use App\Repository\MessageRepository;

/**
 * Reads the page of messages a caller asked for.
 *
 * A page is reached by walking from a message rather than by an offset: before
 * returns the page older than the given message, after the page newer than it,
 * and neither returns the newest page. A browser reading a room and a bot
 * reading it over the JSON API ask the same question, so they share this.
 */
final class MessagePage
{
    public function __construct(private readonly MessageRepository $messages)
    {
    }

    /**
     * @return list<Message>
     */
    public function load(Room $room, string $before, string $after): array
    {
        if ('' !== $before && null !== $message = $this->messages->findInRoom($room, (int) $before)) {
            return $this->messages->findPageBefore($room, $message);
        }

        if ('' !== $after && null !== $message = $this->messages->findInRoom($room, (int) $after)) {
            return $this->messages->findPageAfter($room, $message);
        }

        return $this->messages->findLastPage($room);
    }
}
