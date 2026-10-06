<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RoomData;
use App\Form\OpenRoomType;
use App\Http\Attribute\MapRoom;
use App\Repository\RoomRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AdministerVoter;
use App\Security\Voter\CreateRoomVoter;
use App\Service\RoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Open rooms are visible to every member of the account.
 *
 * An open room holds everyone, so its form does not ask who to let in: it shows
 * the list of people for information and grants access to all of them, along
 * with whoever joins the account later.
 */
final class OpenRoomsController extends AbstractRoomController
{
    public function __construct(
        RoomManager $roomManager,
        UserRepository $users,
        RoomRepository $rooms,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($roomManager, $users, $rooms);
    }

    #[Route('/rooms/opens/new', name: 'rooms_open_new', methods: ['GET'])]
    #[IsGranted(CreateRoomVoter::CAN_CREATE_ROOMS)]
    public function new(): Response
    {
        // The field starts empty so the page shows its placeholder. A name is
        // still required: the form asks for one when it is submitted empty.
        return $this->render('rooms/opens/new.html.twig', [
            'form' => $this->createForm(OpenRoomType::class, new RoomData(), $this->formOptions('rooms_open_create')),
            ...$this->pageData(),
        ]);
    }

    #[Route('/rooms/opens', name: 'rooms_open_create', methods: ['POST'])]
    #[IsGranted(CreateRoomVoter::CAN_CREATE_ROOMS)]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(OpenRoomType::class, $data = new RoomData(), $this->formOptions('rooms_open_create'));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $room = new OpenRoom();
            $room->setName($data->name);
            $room->setCreator($user);

            $this->roomManager->createFor($room, [$user, ...$this->users->findActiveOrdered()]);

            return $this->redirectToRoute('rooms_show', ['id' => $room->getId()]);
        }

        return $this->render('rooms/opens/new.html.twig', [
            'form' => $form,
            ...$this->pageData(),
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/rooms/opens/{id}/edit', name: 'rooms_open_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id, #[CurrentUser] User $user): Response
    {
        $room = $this->roomOf($id, $user);

        if (null === $room) {
            return $this->roomNotFound();
        }

        return $this->render('rooms/opens/edit.html.twig', [
            'room' => $room,
            'form' => $this->createForm(OpenRoomType::class, $this->roomData($room), $this->formOptions('rooms_open_update', $room, 'PUT')),
            ...$this->pageData($this->isGranted(AdministerVoter::CAN_ADMINISTER, $room)),
        ]);
    }

    #[Route('/rooms/opens/{id}', name: 'rooms_open_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER, subject: 'room')]
    public function update(Request $request, #[MapRoom(includeDirect: false)] Room $room): Response
    {
        $form = $this->createForm(OpenRoomType::class, $data = $this->roomData($room), $this->formOptions('rooms_open_update', $room, 'PUT'));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('rooms/opens/edit.html.twig', [
                'room' => $room,
                'form' => $form,
                ...$this->pageData(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // A closed room edited from this page becomes an open one on saving.
        $room = $this->roomManager->changeType($room, OpenRoom::class);
        $room->setName($data->name);
        $this->entityManager->flush();

        $this->roomManager->grant($room, $this->users->findActiveOrdered());

        return $this->redirectToRoute('rooms_show', ['id' => $room->getId()]);
    }
}
