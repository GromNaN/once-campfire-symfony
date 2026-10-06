<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\Enum\MembershipInvolvement;
use App\Entity\Membership;
use App\Entity\User;
use App\Mercure\Broadcaster;
use App\Mercure\Topics;
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
 * How much a person wants to hear about a room.
 *
 * The bell cycles through the levels. Setting a room to invisible also takes it
 * out of the sidebar, which is broadcast to the other tabs of the same user.
 */
final class InvolvementsController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly MembershipRepository $memberships,
        private readonly Broadcaster $broadcaster,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/rooms/{room_id}/involvement', name: 'rooms_involvement', methods: ['GET'], requirements: ['room_id' => '\d+'])]
    public function show(int $room_id, #[CurrentUser] User $user): Response
    {
        $membership = $this->membership($room_id, $user);

        if (null === $membership) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->render('rooms/involvements/show.html.twig', ['membership' => $membership]);
    }

    #[Route('/rooms/{room_id}/involvement', name: 'rooms_involvement_update', methods: ['PUT'], requirements: ['room_id' => '\d+'])]
    #[IsCsrfTokenValid('rooms_involvement', tokenKey: '_csrf_token')]
    public function update(Request $request, int $room_id, #[CurrentUser] User $user): Response
    {
        $membership = $this->membership($room_id, $user);

        if (null === $membership) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $involvement = MembershipInvolvement::tryFrom($request->request->getString('involvement'));

        if (null === $involvement) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $wasInvisible = MembershipInvolvement::Invisible === $membership->getInvolvement();
        $membership->setInvolvement($involvement);
        $this->entityManager->flush();

        $this->broadcastVisibility($membership, $wasInvisible);

        return $this->redirectToRoute('rooms_show', ['id' => $room_id], Response::HTTP_SEE_OTHER);
    }

    /**
     * A room that becomes invisible leaves the sidebar, and one that becomes
     * visible again is put back in its place.
     */
    private function broadcastVisibility(Membership $membership, bool $wasInvisible): void
    {
        $room = $membership->getRoom();
        $user = $membership->getUser();

        if (null === $room || null === $user || $room->isDirect()) {
            return;
        }

        $topic = Topics::userRooms((int) $user->getId());

        if (MembershipInvolvement::Invisible === $membership->getInvolvement()) {
            $this->broadcaster->publish($topic, 'users/sidebars/rooms/removed.stream.twig', ['room' => $room]);

            return;
        }

        if ($wasInvisible) {
            $this->broadcaster->publish($topic, 'users/sidebars/rooms/prepended.stream.twig', [
                'room' => $room,
                'unread' => $membership->isUnread(),
            ]);
        }
    }

    private function membership(int $room_id, User $user): ?Membership
    {
        $room = $this->rooms->findForUser($room_id, $user);

        return null === $room ? null : $this->memberships->findFor($room, $user);
    }
}
