<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Form\AccountUserRoleType;
use App\Form\Data\AccountUserRoleData;
use App\Repository\UserRepository;
use App\Security\Voter\AdministerVoter;
use App\Service\AccountAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The members of the account as an administrator manages them: their role and
 * their departure.
 *
 * Only a member who is still active is reachable, so a deactivated one cannot
 * be acted on twice, and nobody may turn their own role down or remove
 * themselves: the page leaves the controls out, and the server refuses the
 * request if it arrives anyway.
 */
final class UsersController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountAdministration $administration,
    ) {
    }

    #[Route('/account/users/{id}', name: 'account_user_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER)]
    public function update(Request $request, int $id, #[CurrentUser] User $currentUser): Response
    {
        $user = $this->member($id);
        $this->refuseSelf($user, $currentUser);

        $form = $this->createForm(AccountUserRoleType::class, $data = new AccountUserRoleData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->administration->setUserRole(
                $user,
                $data->administrator ? UserRole::Administrator : UserRole::Member,
            );
        }

        return $this->redirectToRoute('account_edit');
    }

    #[Route('/account/users/{id}', name: 'account_user_deactivate', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER)]
    #[IsCsrfTokenValid('account_user_deactivate', tokenKey: '_csrf_token')]
    public function deactivate(int $id, #[CurrentUser] User $currentUser): Response
    {
        $user = $this->member($id);
        $this->refuseSelf($user, $currentUser);

        $this->administration->deactivateUser($user);

        return $this->redirectToRoute('account_edit');
    }

    private function member(int $id): User
    {
        $user = $this->users->findActiveMember($id);

        if (null === $user) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    private function refuseSelf(User $user, User $currentUser): void
    {
        if ($user->getId() === $currentUser->getId()) {
            throw new AccessDeniedException('You cannot change your own role or remove yourself.');
        }
    }
}
