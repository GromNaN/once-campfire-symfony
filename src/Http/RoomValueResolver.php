<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Room;
use App\Entity\User;
use App\Http\Attribute\MapRoom;
use App\Repository\RoomRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Resolves an #[MapRoom] argument from the rooms of the current user.
 *
 * The lookup is scoped to the memberships of the current user, the way the
 * original application reads a room, so the resolver never hands out a room the
 * user cannot reach. A room that is out of reach is answered as not found, not
 * as forbidden: the address of a room the user is not in is not worth telling
 * apart from an address that names nothing.
 *
 * The room is read by its identifier, which the route requirement already
 * guarantees to be a number, so a missing or malformed parameter is answered
 * the same way as a room that does not exist.
 */
final class RoomValueResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $attributes = $argument->getAttributes(MapRoom::class, ArgumentMetadata::IS_INSTANCEOF);

        if ([] === $attributes) {
            return [];
        }

        $options = $attributes[0];
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('A room can only be resolved for a signed in user.');
        }

        // The route parameter is the one the attribute declares, which is the
        // identifier by default, the same way #[MapEntity] reads it.
        $name = \is_string($options->id) ? $options->id : $argument->getName();
        $id = $request->attributes->get($name);

        if (!\is_numeric($id)) {
            throw new NotFoundHttpException('Room not found or inaccessible');
        }

        $room = $options->includeDirect
            ? $this->rooms->findForUser((int) $id, $user)
            : $this->rooms->findNonDirectForUser((int) $id, $user);

        if (null === $room) {
            throw new NotFoundHttpException('Room not found or inaccessible');
        }

        return [$room];
    }
}
