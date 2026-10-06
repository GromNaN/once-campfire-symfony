<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Matches the string values stored by the Rails enum on memberships.involvement.
 */
enum MembershipInvolvement: string
{
    case Invisible = 'invisible';
    case Nothing = 'nothing';
    case Mentions = 'mentions';
    case Everything = 'everything';

    /**
     * The levels the bell cycles through, per kind of room. A direct
     * conversation has no silent invisible setting, since the other person
     * already knows who they are talking to.
     */
    private const SHARED_ORDER = [self::Mentions, self::Everything, self::Nothing, self::Invisible];
    private const DIRECT_ORDER = [self::Everything, self::Nothing];

    /**
     * The level reached by clicking the notification bell again.
     */
    public function next(bool $direct): self
    {
        $order = $direct ? self::DIRECT_ORDER : self::SHARED_ORDER;
        $index = array_search($this, $order, true);

        return $order[false === $index ? 0 : ($index + 1) % \count($order)];
    }

    public function label(): string
    {
        return match ($this) {
            self::Mentions => 'Notifying about @ mentions',
            self::Everything => 'Notifying about all messages',
            self::Nothing => 'Notifications are off',
            self::Invisible => 'Notifications are off and room invisible in sidebar',
        };
    }

    /**
     * Levels that receive a push notification for a new message.
     */
    public function notifiesOnEveryMessage(): bool
    {
        return self::Everything === $this;
    }

    public function notifiesOnMentions(): bool
    {
        return \in_array($this, [self::Mentions, self::Everything], true);
    }
}
