<?php

declare(strict_types=1);

namespace App\Mercure;

/**
 * Names of the Mercure topics the application publishes to.
 *
 * Every topic is private: a browser can only subscribe to the topics its
 * subscriber token grants, and that token is rebuilt on each response from the
 * rooms the user is a member of. Revoking a membership therefore stops delivery
 * at the next response, which is what the original application guards its
 * ActionCable streams for.
 */
final class Topics
{
    /**
     * Room list changes that every signed in user may see, such as a new open
     * room appearing in the sidebar.
     */
    public const ROOMS = 'rooms';

    public static function roomMessages(int $roomId): string
    {
        return \sprintf('room/%d/messages', $roomId);
    }

    public static function roomTyping(int $roomId): string
    {
        return \sprintf('room/%d/typing', $roomId);
    }

    public static function userRooms(int $userId): string
    {
        return \sprintf('user/%d/rooms', $userId);
    }

    public static function userUnreads(int $userId): string
    {
        return \sprintf('user/%d/unreads', $userId);
    }

    public static function userReads(int $userId): string
    {
        return \sprintf('user/%d/reads', $userId);
    }
}
