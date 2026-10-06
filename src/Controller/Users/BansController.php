<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\BanAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Banning a member and lifting the ban.
 *
 * Only an administrator may do either, and nobody may ban themselves: the page
 * leaves the button out, and the server refuses the request if it arrives
 * anyway. Both answers send the reader back to the page of the member, which
 * then shows the ban or the way to lift it.
 */
final class BansController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly BanAdministration $bans,
    ) {
    }

    #[Route('/users/{id}/ban', name: 'user_ban_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('user_ban', tokenKey: '_csrf_token')]
    public function create(int $id, #[CurrentUser] User $currentUser): Response
    {
        $user = $this->target($id, $currentUser);
        $this->bans->ban($user);

        return $this->redirectToRoute('user_show', ['id' => $user->getId()]);
    }

    #[Route('/users/{id}/ban', name: 'user_ban_destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('user_ban', tokenKey: '_csrf_token')]
    public function destroy(int $id, #[CurrentUser] User $currentUser): Response
    {
        $user = $this->target($id, $currentUser);
        $this->bans->unban($user);

        return $this->redirectToRoute('user_show', ['id' => $user->getId()]);
    }

    /**
     * The member the ban applies to.
     *
     * A member who is not there, and the administrator themselves, are both
     * not found: a ban is about somebody else.
     */
    private function target(int $id, User $currentUser): User
    {
        $user = $this->users->find($id);

        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        if ($user->getId() === $currentUser->getId()) {
            throw new AccessDeniedException('You cannot ban yourself.');
        }

        return $user;
    }
}
