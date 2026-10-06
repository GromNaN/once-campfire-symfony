<?php

declare(strict_types=1);

namespace App\Room;

use App\Entity\Room;
use App\Entity\User;

/**
 * Names a room the way the reader sees it.
 *
 * An open or closed room is known by its name. A direct room has none, so it is
 * shown as the people in it, which is also what the original application does.
 */
final class RoomDisplayName
{
    public function displayName(Room $room, ?User $forUser = null): string
    {
        if (!$room->isDirect()) {
            return (string) $room->getName();
        }

        $names = [];

        foreach ($room->getMembers() as $member) {
            if ($member->getId() !== $forUser?->getId()) {
                $names[] = $member->getName();
            }
        }

        if ([] === $names) {
            return (string) $forUser?->getName();
        }

        return $this->sentence($names);
    }

    /**
     * Joins names the way Rails' to_sentence does: "A and B", "A, B, and C".
     *
     * @param list<string> $names
     */
    private function sentence(array $names): string
    {
        $last = array_pop($names);

        if ([] === $names) {
            return (string) $last;
        }

        return 1 === \count($names)
            ? \sprintf('%s and %s', $names[0], $last)
            : \sprintf('%s, and %s', implode(', ', $names), $last);
    }
}
