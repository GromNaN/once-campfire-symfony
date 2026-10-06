<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\Membership;
use App\Entity\User;
use App\Mercure\RoomBroadcast;
use App\Repository\MembershipRepository;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Records that a browser is watching a room.
 *
 * Mercure only pushes to a client, so the "I am here" signal the original
 * application gets from opening an ActionCable subscription is an explicit
 * POST from the browser instead. A membership counts as connected for a minute
 * after the last sign of life, which is what decides whether a new message
 * leaves the room unread for that person.
 */
final class PresenceController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly MembershipRepository $memberships,
        private readonly RoomBroadcast $broadcast,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/rooms/{room_id}/presence', name: 'rooms_presence', methods: ['POST'], requirements: ['room_id' => '\d+'])]
    #[IsCsrfTokenValid('rooms_presence', tokenKey: '_csrf_token')]
    public function create(Request $request, int $room_id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($room_id, $user);

        if (null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $membership = $this->memberships->findFor($room, $user);

        if (null === $membership) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        match ($request->request->getString('action')) {
            'absent' => $membership->disconnect(),
            'refresh' => $membership->refreshConnection(),
            default => $this->present($membership, $user),
        };

        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Arriving in a room counts as reading it, so the unread marker goes away
     * for the other tabs of the same user as well.
     */
    private function present(Membership $membership, User $user): void
    {
        $membership->present();
        $this->entityManager->flush();

        $this->broadcast->publishReadState($user, $membership);
    }
}
