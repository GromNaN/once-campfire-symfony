<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\User;
use App\Message\MessageBody;
use App\Repository\MessageRepository;
use App\Repository\RoomRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Catches a room up after the connection was lost.
 *
 * A browser that reconnects cannot replay what it missed on the Mercure
 * stream, so it asks for everything created or changed since the moment it
 * last heard from the server and receives the missing Turbo Streams.
 */
final class RefreshesController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly MessageRepository $messages,
        private readonly MessageBody $body,
    ) {
    }

    #[Route('/rooms/{room_id}/refresh', name: 'rooms_refresh', methods: ['GET'], requirements: ['room_id' => '\d+'])]
    public function show(Request $request, int $room_id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($room_id, $user);

        if (null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $since = $this->since($request);
        $created = $this->messages->findCreatedAfter($room, $since);
        $updated = $this->messages->findUpdatedAfter($room, $since, $created);

        // The stream draws every message it carries, so their bodies and their
        // files are read in two queries rather than one per message.
        $this->body->prime([...$created, ...$updated]);

        // The answer is always a stream: the browser asks for this page only to
        // catch up after a lost connection, and reads the answer as a stream.
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('rooms/refreshes/show.stream.twig', [
            'room' => $room,
            'new_messages' => $created,
            'updated_messages' => $updated,
        ]);
    }

    /**
     * The client sends the epoch in milliseconds, the way the original
     * application does.
     */
    private function since(Request $request): \DateTimeImmutable
    {
        $milliseconds = (int) $request->query->get('since', '0');

        return \DateTimeImmutable::createFromFormat('U.u', number_format($milliseconds / 1000, 6, '.', ''))
            ?: new \DateTimeImmutable('@0');
    }
}
