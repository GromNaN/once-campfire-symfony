<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The page of a person: their picture, their name and a way to talk to them.
 *
 * Any member may look at any other, which is what the original does. What the
 * page offers depends on who is looking: an administrator sees the address of
 * the person and the link that signs them in on another device, and the person
 * themselves sees the way to their own settings.
 */
final class UsersController extends AbstractController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    #[Route('/users/{id}', name: 'user_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] User $reader): Response
    {
        $user = $this->users->find($id);

        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        return $this->render('users/show.html.twig', [
            'user' => $user,
            'can_administer' => $reader->isAdministrator(),
        ]);
    }
}
