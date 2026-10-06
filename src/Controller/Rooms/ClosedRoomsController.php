<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\ClosedRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RoomData;
use App\Form\RoomType;
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
 * Closed rooms are visible only to the members who were granted access.
 *
 * Access is granted person by person, and the page that edits a closed room
 * shows the whole account so that access can be taken away as well as given.
 */
final class ClosedRoomsController extends AbstractRoomController
{
    public function __construct(
        RoomManager $roomManager,
        UserRepository $users,
        RoomRepository $rooms,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($roomManager, $users, $rooms);
    }

    #[Route('/rooms/closeds/new', name: 'rooms_closed_new', methods: ['GET'])]
    #[IsGranted(CreateRoomVoter::CAN_CREATE_ROOMS)]
    public function new(): Response
    {
        $data = new RoomData();
        $data->name = self::DEFAULT_ROOM_NAME;

        return $this->render('rooms/closeds/new.html.twig', [
            'form' => $this->createForm(RoomType::class, $data, $this->formOptions('rooms_closed_create')),
            ...$this->pageData(),
        ]);
    }

    #[Route('/rooms/closeds', name: 'rooms_closed_create', methods: ['POST'])]
    #[IsGranted(CreateRoomVoter::CAN_CREATE_ROOMS)]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(RoomType::class, $data = new RoomData(), $this->formOptions('rooms_closed_create'));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $room = new ClosedRoom();
            $room->setName($data->name);
            $room->setCreator($user);

            $this->roomManager->createFor($room, [$user, ...$data->members]);

            return $this->redirectToRoute('rooms_show', ['id' => $room->getId()]);
        }

        return $this->render('rooms/closeds/new.html.twig', [
            'form' => $form,
            ...$this->pageData(),
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/rooms/closeds/{id}/edit', name: 'rooms_closed_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id, #[CurrentUser] User $user): Response
    {
        $room = $this->roomOf($id, $user);

        if (null === $room) {
            return $this->roomNotFound();
        }

        return $this->render('rooms/closeds/edit.html.twig', [
            'room' => $room,
            'form' => $this->createForm(RoomType::class, $this->roomData($room), $this->formOptions('rooms_closed_update', $room, 'PUT')),
            ...$this->pageData($this->isGranted(AdministerVoter::CAN_ADMINISTER, $room)),
        ]);
    }

    #[Route('/rooms/closeds/{id}', name: 'rooms_closed_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER, subject: 'room')]
    public function update(Request $request, #[MapRoom(includeDirect: false)] Room $room): Response
    {
        $form = $this->createForm(RoomType::class, $data = $this->roomData($room), $this->formOptions('rooms_closed_update', $room, 'PUT'));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('rooms/closeds/edit.html.twig', [
                'room' => $room,
                'form' => $form,
                ...$this->pageData(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // An open room edited from this page becomes a closed one on saving.
        $room = $this->roomManager->changeType($room, ClosedRoom::class);
        $room->setName($data->name);
        $this->entityManager->flush();

        $this->roomManager->revise($room, $data->members);

        return $this->redirectToRoute('rooms_show', ['id' => $room->getId()]);
    }
}
