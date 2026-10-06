<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\User;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fans the changes of a room out to the browsers watching it.
 *
 * This replaces the ActionCable broadcasts of the original application. The
 * room stream carries the message list, and the unread notice is sent to each
 * member on their own topic, so the timing of activity in a room only reaches
 * the people who are in it.
 */
final class RoomBroadcast
{
    public function __construct(
        private readonly Broadcaster $broadcaster,
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function messageCreated(Message $message): void
    {
        $room = $message->getRoom();

        $this->broadcaster->publish(
            Topics::roomMessages((int) $room?->getId()),
            'rooms/messages/create.stream.twig',
            ['room' => $room, 'message' => $message],
        );

        $this->markUnread($message);
    }

    public function messageUpdated(Message $message): void
    {
        $room = $message->getRoom();

        $this->broadcaster->publish(
            Topics::roomMessages((int) $room?->getId()),
            'rooms/messages/update.stream.twig',
            ['room' => $room, 'message' => $message],
        );
    }

    public function messageRemoved(Message $message): void
    {
        $room = $message->getRoom();

        $this->broadcaster->publish(
            Topics::roomMessages((int) $room?->getId()),
            'rooms/messages/destroy.stream.twig',
            ['room' => $room, 'message' => $message],
        );
    }

    /**
     * Adds a boost to the list under its message, in every browser watching
     * the room. A boost never marks a room unread, which is what the original
     * application decides too.
     */
    public function boostCreated(Boost $boost): void
    {
        $message = $boost->getMessage();
        $room = $message?->getRoom();

        if (null === $message || null === $room) {
            return;
        }

        $this->broadcaster->publish(
            Topics::roomMessages((int) $room->getId()),
            'rooms/messages/boosts/create.stream.twig',
            ['message' => $message, 'boost' => $boost],
        );
    }

    public function boostRemoved(Boost $boost): void
    {
        $message = $boost->getMessage();
        $room = $message?->getRoom();

        if (null === $message || null === $room) {
            return;
        }

        $this->broadcaster->publish(
            Topics::roomMessages((int) $room->getId()),
            'rooms/messages/boosts/destroy.stream.twig',
            ['message' => $message, 'boost' => $boost],
        );
    }

    /**
     * Tells every member who is not currently reading the room that it has
     * something new, which is what paints the unread marker in the sidebar.
     */
    private function markUnread(Message $message): void
    {
        $room = $message->getRoom();
        $author = $message->getCreator();

        if (null === $room || null === $author) {
            return;
        }

        $memberships = $this->memberships->findToMarkUnread($room, $author);

        foreach ($memberships as $membership) {
            $membership->setUnreadAt($message->getCreatedAt());
        }

        $this->entityManager->flush();

        foreach ($memberships as $membership) {
            $this->publishUnread($membership);
        }
    }

    private function publishUnread(Membership $membership): void
    {
        $user = $membership->getUser();
        $room = $membership->getRoom();

        if (null === $user || null === $room) {
            return;
        }

        $this->broadcaster->publish(
            Topics::userUnreads((int) $user->getId()),
            $this->unreadTemplate($room),
            ['room' => $room, 'membership' => $membership, 'unread' => true],
        );
    }

    /**
     * Marks a room as read for one user, used when they open it.
     */
    public function roomRead(User $user, Membership $membership): void
    {
        $membership->read();
        $this->entityManager->flush();

        $this->publishReadState($user, $membership);
    }

    /**
     * Tells the sidebar of one user that a room has nothing new. The caller is
     * responsible for having saved the membership already.
     */
    public function publishReadState(User $user, Membership $membership): void
    {
        $room = $membership->getRoom();

        if (null === $room) {
            return;
        }

        $this->broadcaster->publish(
            Topics::userReads((int) $user->getId()),
            $this->unreadTemplate($room),
            ['room' => $room, 'membership' => $membership, 'unread' => false],
        );
    }

    private function unreadTemplate(Room $room): string
    {
        return $room->isDirect()
            ? 'users/sidebars/rooms/direct.stream.twig'
            : 'users/sidebars/rooms/shared.stream.twig';
    }
}
