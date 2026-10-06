<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\LastRoom;
use App\Room\LastVisitedRoom;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The root page.
 *
 * A user who already has rooms lands on the last room they opened, and anyone
 * else gets the empty state that invites them to start a conversation.
 */
final class RootController extends AbstractController
{
    public function __construct(private readonly LastVisitedRoom $lastRoom)
    {
    }

    #[Route('/', name: 'root', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $room = $this->lastRoom->for($user, $request->cookies->get(LastRoom::COOKIE));

        return null === $room
            ? $this->render('welcome/show.html.twig')
            : new RedirectResponse($this->generateUrl('rooms_show', ['id' => $room->getId()]));
    }
}
