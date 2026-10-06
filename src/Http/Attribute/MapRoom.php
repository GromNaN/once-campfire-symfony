<?php

declare(strict_types=1);

namespace App\Http\Attribute;

use App\Entity\Room;
use App\Http\RoomValueResolver;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

/**
 * The room the address points at, when the current user is a member of it.
 *
 * It extends #[MapEntity] so that the route parameter that names the room is
 * declared the same way, with the room as the class and the "id" parameter as
 * the identifier by default. The resolution itself is delegated to a resolver
 * of its own, because the room is looked up among the rooms the current user
 * belongs to rather than by identifier alone.
 *
 * That scope is what the original application uses: the room is read from the
 * memberships of the current user before anything else is done with it. A room
 * the user is not in is therefore not found rather than forbidden, and an
 * account administrator cannot reach a room they are not a member of either.
 *
 * A private conversation is in reach by default, because reading or deleting a
 * room reaches the conversations the user takes part in. The pages that edit a
 * room ask for conversations to be left out, since an open room and a closed
 * room turn into each other on saving and a conversation must never be turned
 * into one.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapRoom extends MapEntity
{
    /**
     * @param bool   $includeDirect whether a private conversation can be resolved too
     * @param string $id            the route parameter that holds the room identifier
     */
    public function __construct(
        public readonly bool $includeDirect = true,
        string $id = 'id',
    ) {
        parent::__construct(class: Room::class, id: $id, resolver: RoomValueResolver::class);
    }
}
