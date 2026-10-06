<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\User;
use App\Mercure\Broadcaster;
use App\Mercure\Topics;
use App\Repository\RoomRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Tells the other people in a room that someone is writing.
 *
 * The browser posts here when the composer becomes non empty and when it is
 * cleared or sent, and the notice is published on the typing topic of the room.
 */
final class TypingController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly Broadcaster $broadcaster,
    ) {
    }

    #[Route('/rooms/{room_id}/typing', name: 'rooms_typing', methods: ['POST'], requirements: ['room_id' => '\d+'])]
    public function create(Request $request, int $room_id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($room_id, $user);

        if (null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $stopped = 'stop' === $request->request->getString('action');

        $this->broadcaster->publish(
            Topics::roomTyping($room_id),
            $stopped ? 'rooms/typing/stop.stream.twig' : 'rooms/typing/start.stream.twig',
            ['room' => $room, 'user' => $user],
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
