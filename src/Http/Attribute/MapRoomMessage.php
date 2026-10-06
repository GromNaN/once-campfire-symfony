<?php

declare(strict_types=1);

namespace App\Http\Attribute;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;

/**
 * A message reached through the room it belongs to.
 *
 * The message is looked up by the room named in the address and by its own
 * identifier, so a message can only ever be reached through the room it is in.
 * Every address that names a message inside a room carries the same two
 * parameters, so the mapping is written once here rather than repeated on each
 * action. The class is read from the type of the argument, as it is for
 * #[MapEntity].
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapRoomMessage extends MapEntity
{
    public function __construct()
    {
        parent::__construct(mapping: ['room_id' => 'room', 'id' => 'id']);
    }
}
