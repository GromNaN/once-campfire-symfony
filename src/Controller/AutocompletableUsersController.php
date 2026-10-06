<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Room;
use App\Entity\User;
use App\Repository\RoomRepository;
use App\Repository\UserRepository;
use App\Twig\AvatarExtension;
use App\Twig\SignedIdExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The users a mention can point at.
 *
 * The editor asks for this list while someone types, and so does the picker
 * that adds a person to a direct room. A room narrows the list to its members,
 * because a mention of someone outside the room would reach nobody.
 *
 * Two answers are served from the same list: the prompt items the editor reads,
 * and the plain records a client of its own reads.
 */
final class AutocompletableUsersController extends AbstractController
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoomRepository $rooms,
        private readonly AvatarExtension $avatars,
        private readonly SignedIdExtension $signedIds,
    ) {
    }

    #[Route('/autocompletable/users', name: 'autocompletable_users', methods: ['GET'])]
    #[Route('/autocompletable/users.json', name: 'autocompletable_users_json', methods: ['GET'], defaults: ['_format' => 'json'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $room = $this->room($request, $user);

        if ($request->query->has('room_id') && null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // The editor filters with "filter", the other pickers with "query".
        $query = $request->query->getString('filter') ?: $request->query->getString('query');
        $page = max(1, $request->query->getInt('page', 1));

        $users = $this->users->findForAutocomplete(
            $room,
            $query,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        if ($request->getRequestFormat() === 'json') {
            return new JsonResponse(array_map($this->record(...), $users));
        }

        return $this->render('autocompletable/users/index.html.twig', ['users' => $users]);
    }

    /**
     * The room the list is narrowed to, when the caller asked for one and is a
     * member of it.
     */
    private function room(Request $request, User $user): ?Room
    {
        $roomId = $request->query->getString('room_id');

        if ('' === $roomId) {
            return null;
        }

        return $this->rooms->findForUser((int) $roomId, $user);
    }

    /**
     * @return array{name: string, value: int, avatar_url: string, sgid: string}
     */
    private function record(User $user): array
    {
        return [
            'name' => $user->getName(),
            'value' => (int) $user->getId(),
            'avatar_url' => $this->avatars->path($user),
            'sgid' => $this->signedIds->attachableSgid($user),
        ];
    }
}
