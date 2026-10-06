<?php

declare(strict_types=1);

namespace App\Boost;

/**
 * The reactions offered as one click boosts.
 *
 * They are the same eight the original application offers, in the same order,
 * with the same names for anyone who cannot see them.
 */
final class Reactions
{
    /**
     * @var array<string, string> the character, and what it is called
     */
    public const ALL = [
        '👍' => 'Thumbs up',
        '👏' => 'Clapping',
        '👋' => 'Waving hand',
        '💪' => 'Muscle',
        '❤️' => 'Red heart',
        '😂' => 'Face with tears of joy',
        '🎉' => 'Party popper',
        '🔥' => 'Fire',
    ];
}
