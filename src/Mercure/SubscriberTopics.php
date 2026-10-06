<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Entity\User;
use App\Repository\MembershipRepository;

/**
 * The Mercure topics a user may subscribe to.
 *
 * Two things are needed to receive a topic: the subscriber token has to grant
 * it, and the subscription has to ask for it. The token is minted per response
 * by the authorization listener; the asked topics come from here, so that the
 * page can build the address the browser opens its stream on.
 */
final class SubscriberTopics
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    /**
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        $id = (int) $user->getId();

        $topics = [
            Topics::ROOMS,
            Topics::userRooms($id),
            Topics::userUnreads($id),
            Topics::userReads($id),
        ];

        foreach ($this->memberships->findBy(['user' => $user]) as $membership) {
            $roomId = $membership->getRoom()?->getId();

            if (null !== $roomId) {
                $topics[] = Topics::roomMessages($roomId);
                $topics[] = Topics::roomTyping($roomId);
            }
        }

        return $topics;
    }

    /**
     * The query string asking the hub for those topics.
     *
     * A topic is repeated once per topic, which is the shape the hub reads. The
     * array syntax http_build_query() would use is not understood by it.
     */
    public function queryForUser(User $user): string
    {
        $pairs = array_map(
            static fn (string $topic): string => 'topic='.rawurlencode($topic),
            $this->forUser($user),
        );

        return '?'.implode('&', $pairs);
    }
}
