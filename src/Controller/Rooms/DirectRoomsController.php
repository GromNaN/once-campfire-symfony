<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\DirectRoom;
use App\Entity\User;
use App\Form\Data\RoomData;
use App\Form\DirectRoomType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Direct rooms are private conversations between a fixed set of people.
 *
 * A set of people always refers to the same room, so starting a conversation
 * that already exists opens it instead of creating a second one.
 */
final class DirectRoomsController extends AbstractRoomController
{
    #[Route('/rooms/directs/new', name: 'rooms_direct_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('rooms/directs/new.html.twig', [
            'form' => $this->createForm(DirectRoomType::class, new RoomData(), $this->formOptions('rooms_direct_create')),
        ]);
    }

    #[Route('/rooms/directs', name: 'rooms_direct_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(DirectRoomType::class, $data = new RoomData(), $this->formOptions('rooms_direct_create'));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $room = $this->roomManager->findOrCreateDirect([$user, ...$data->members]);

            return $this->redirectToRoute('rooms_show', ['id' => $room->getId()]);
        }

        return $this->render(
            'rooms/directs/new.html.twig',
            ['form' => $form],
            new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    /**
     * Everyone in a conversation can administer it, so this page only asks that
     * the reader is one of the people it gathers.
     */
    #[Route('/rooms/directs/{id}/edit', name: 'rooms_direct_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($id, $user, DirectRoom::class);

        if (null === $room) {
            return $this->roomNotFound();
        }

        return $this->render('rooms/directs/edit.html.twig', ['room' => $room]);
    }
}
