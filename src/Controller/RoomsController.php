<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\MessageData;
use App\Form\MessageType;
use App\Http\LastRoom;
use App\Repository\MessageRepository;
use App\Repository\RoomRepository;
use App\Security\Voter\AdministerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class RoomsController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly MessageRepository $messages,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/rooms', name: 'rooms_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findLastForUser($user);

        return null === $room
            ? $this->redirectToRoute('root')
            : $this->redirectToRoute('rooms_show', ['id' => $room->getId()]);
    }

    #[Route('/rooms/{id}', name: 'rooms_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($id, $user);

        if (null === $room) {
            return $this->roomNotFound();
        }

        return $this->roomPage($room, $this->messages->findLastPage($room));
    }

    /**
     * Permalink to a room opened at a given message.
     */
    #[Route('/rooms/{room_id}/@{message_id}', name: 'rooms_at_message', methods: ['GET'], requirements: ['room_id' => '\d+', 'message_id' => '\d+'])]
    public function atMessage(int $room_id, int $message_id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($room_id, $user);

        if (null === $room) {
            return $this->roomNotFound();
        }

        $message = $this->messages->findInRoom($room, $message_id);

        if (null === $message) {
            return $this->roomNotFound();
        }

        return $this->roomPage($room, [
            ...$this->messages->findPageBefore($room, $message),
            $message,
            ...$this->messages->findPageAfter($room, $message),
        ]);
    }

    #[Route('/rooms/{id}', name: 'rooms_destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('room_destroy', tokenKey: '_csrf_token')]
    public function destroy(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($id, $user);

        if (null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(AdministerVoter::CAN_ADMINISTER, $room);

        $this->entityManager->remove($room);
        $this->entityManager->flush();

        return $this->redirectToRoute('root');
    }

    /**
     * @param list<Message> $messages
     */
    private function roomPage(Room $room, array $messages): Response
    {
        $response = $this->render('rooms/show.html.twig', [
            'room' => $room,
            'messages' => $messages,
            'composer' => $this->createForm(MessageType::class, new MessageData()),
            'show_invitation' => $this->showsInvitation($room),
        ]);

        // The next visit returns the visitor to this room.
        $response->headers->setCookie(
            Cookie::create(LastRoom::COOKIE, (string) $room->getId())
                ->withExpires(time() + LastRoom::LIFETIME)
                ->withPath('/')
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }

    /**
     * Whether the room welcomes the reader and shows how to invite people.
     *
     * Only the room the account was created with does, and only while it still
     * holds no more than its first page of messages, so that it reads as the
     * start of the conversation rather than as a message someone sent.
     */
    private function showsInvitation(Room $room): bool
    {
        $original = $this->rooms->findOriginal();

        return null !== $original
            && $original->getId() === $room->getId()
            && $this->messages->countInRoom($room) <= Message::PAGE_SIZE;
    }

    private function roomNotFound(): RedirectResponse
    {
        $this->addFlash('alert', 'Room not found or inaccessible');

        return $this->redirectToRoute('root');
    }
}
