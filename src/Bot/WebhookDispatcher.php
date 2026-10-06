<?php

declare(strict_types=1);

namespace App\Bot;

use App\ActionText\AttachableRenderer;
use App\Entity\Message;
use App\Entity\User;
use App\Message\DeliverWebhook;
use App\Message\MessageBody;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Decides which bots hear about a new message, and asks the worker to tell
 * them.
 *
 * A message reaches a bot in a direct room, which exists to talk to it, or a
 * bot the message mentions, which is what an "@name" in the body means. The
 * author is left out so that a bot answering a message never answers itself.
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly UserRepository $users,
        private readonly MessageBody $body,
        private readonly AttachableRenderer $attachables,
    ) {
    }

    public function dispatchFor(Message $message): void
    {
        foreach ($this->botsToNotify($message) as $bot) {
            $webhook = $bot->getWebhook();

            if (null !== $webhook) {
                $this->bus->dispatch(new DeliverWebhook((int) $webhook->getId(), (int) $message->getId()));
            }
        }
    }

    /**
     * @return list<User>
     */
    private function botsToNotify(Message $message): array
    {
        $room = $message->getRoom();

        if (null === $room) {
            return [];
        }

        if ($room->isDirect()) {
            $bots = $this->users->findActiveBotsInRoom($room);
        } else {
            $ids = $this->mentionedIds($message);

            // A shared room is quiet by default: a bot is told about a message
            // only when the message names it.
            $bots = [] === $ids ? [] : $this->users->findActiveBotsInRoom($room, $ids);
        }

        return array_values(array_filter(
            $bots,
            static fn (User $bot) => $bot->getId() !== $message->getCreator()?->getId(),
        ));
    }

    /**
     * The identifiers of the users a message mentions. A mention of someone who
     * is not in the room reaches nobody, which is what narrowing the list to
     * the members of the room settles.
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
