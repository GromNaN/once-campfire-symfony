<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RoomData;
use App\Repository\RoomRepository;
use App\Repository\UserRepository;
use App\Service\RoomManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Shared behaviour of the room controllers.
 *
 * Each room type lives under its own path, so that one namespace cannot be used
 * to reach another type of room.
 */
abstract class AbstractRoomController extends AbstractController
{
    public function __construct(
        protected readonly RoomManager $roomManager,
        protected readonly UserRepository $users,
        protected readonly RoomRepository $rooms,
    ) {
    }

    /**
     * Where a room form saves, and how.
     *
     * The form is shown on a page of its own, which is not where it saves, and
     * saving an existing room replaces it rather than creating another one, so
     * both the address and the method are passed in rather than left to the
     * browser.
     *
     * @return array{action: string, method: string}
     */
    protected function formOptions(string $route, ?Room $room = null, string $method = 'POST'): array
    {
        $parameters = null === $room ? [] : ['id' => $room->getId()];

        return ['action' => $this->generateUrl($route, $parameters), 'method' => $method];
    }

    /**
     * The values every room page hands to its template.
     *
     * @return array{users: list<User>, can_administer: bool}
     */
    protected function pageData(bool $canAdminister = true): array
    {
        return [
            'users' => $this->users->findActiveOrdered(),
            'can_administer' => $canAdminister,
        ];
    }

    /**
     * A room the user is a member of, or nothing when it is out of reach.
     */
    protected function roomOf(int $id, User $user): ?Room
    {
        return $this->rooms->findNonDirectForUser($id, $user);
    }

    /**
     * The values an edit form starts from, which are the ones the room holds.
     */
    protected function roomData(Room $room): RoomData
    {
        $data = new RoomData();
        $data->name = $room->getName();
        $data->members = $room->getMembers();

        return $data;
    }

    /**
     * A room that is out of reach is not worth a page of its own, so the reader
     * is sent back to where they were with a word about it.
     */
    protected function roomNotFound(): RedirectResponse
    {
        $this->addFlash('alert', 'Room not found or inaccessible');

        return $this->redirectToRoute('root');
    }
}
