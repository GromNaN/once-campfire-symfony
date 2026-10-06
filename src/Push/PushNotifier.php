<?php

declare(strict_types=1);

namespace App\Push;

use App\ActionText\AttachableRenderer;
use App\Entity\Message;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Message\MessageBody;
use App\Repository\MembershipRepository;
use App\Repository\PushSubscriptionRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells the readers who are away that a room has something new.
 *
 * The notification names the room in a shared room and the author in a direct
 * conversation, and carries what was written, which is what the original
 * application puts on the lock screen. A reader is only told once however many
 * browsers they registered, and the badge they are given counts the rooms they
 * have not caught up with.
 */
final class PushNotifier
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly MembershipRepository $memberships,
        private readonly PushSender $sender,
        private readonly MessageBody $body,
        private readonly AttachableRenderer $attachables,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function notifyFor(Message $message): void
    {
        $room = $message->getRoom();
        $author = $message->getCreator();

        if (null === $room || null === $author) {
            return;
        }

        $subscriptions = $this->subscriptions->findForMessage(
            $message,
            $this->mentionedIds($message),
            $this->clock->now(),
        );

        foreach ($this->byUser($subscriptions) as $user => $list) {
            $recipient = $list[0]->getUser();

            if (null !== $recipient && (int) $recipient->getId() === $user) {
                $this->sender->send($list, $this->payloadFor($message, $recipient));
            }
        }
    }

    /**
     * The subscriptions of one reader, so that the badge is counted once for
     * each of them rather than once for each browser they registered.
     *
     * @param list<PushSubscription> $subscriptions
     *
     * @return array<int, list<PushSubscription>>
     */
    private function byUser(array $subscriptions): array
    {
        $byUser = [];

        foreach ($subscriptions as $subscription) {
            $id = $subscription->getUser()?->getId();

            if (null !== $id) {
                $byUser[$id][] = $subscription;
            }
        }

        return $byUser;
    }

    private function payloadFor(Message $message, User $recipient): PushPayload
    {
        $room = $message->getRoom();
        $author = $message->getCreator();
        $text = $this->body->plainText($message);

        // A direct conversation has no name of its own, so the notification
        // says who wrote rather than where.
        $title = $room?->isDirect()
            ? (string) $author?->getName()
            : (string) $room?->getName();

        return new PushPayload(
            title: $title,
            body: $room?->isDirect() ? $text : \sprintf('%s: %s', $author?->getName(), $text),
            path: $this->urlGenerator->generate('rooms_show', ['id' => $room?->getId()], UrlGeneratorInterface::ABSOLUTE_PATH),
            badge: $this->memberships->countUnreadForUser($recipient),
            icon: $this->urlGenerator->generate('account_logo_show', [], UrlGeneratorInterface::ABSOLUTE_PATH),
        );
    }

    /**
     * The identifiers of the users a message mentions, which decides who is
     * told when they only asked to hear about mentions.
     *
     * @return list<int>
     */
    private function mentionedIds(Message $message): array
    {
        $ids = [];

        foreach ($this->attachables->mentionedUsers($this->body->stored($message)) as $user) {
            $ids[] = (int) $user->getId();
        }

        return $ids;
    }
}
