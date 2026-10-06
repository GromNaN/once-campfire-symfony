<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\Entity\Account;
use App\Entity\ActiveStorageBlob;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Form\AccountSettingsType;
use App\Form\AccountType;
use App\Form\AccountUserRoleType;
use App\Form\Data\AccountData;
use App\Form\Data\AccountSettingsData;
use App\Form\Data\AccountUserRoleData;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AdministerVoter;
use App\Service\AccountAdministration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The account page: its name, its picture, its join link and its members.
 *
 * Everyone signed in may look at it, and only an administrator may change
 * anything on it, which is what the original does: the page is the same for
 * all, with the controls left out for the members.
 */
final class AccountsController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UserRepository $users,
        private readonly Attachments $attachments,
        private readonly BlobStorage $storage,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountAdministration $administration,
    ) {
    }

    #[Route('/account', name: 'account_edit', methods: ['GET'])]
    public function edit(#[CurrentUser] User $user): Response
    {
        $account = $this->account();
        $canAdminister = $user->isAdministrator();

        $administrators = [];
        $members = [];
        $roleForms = [];

        foreach ($this->users->findForAccountOrdered($canAdminister) as $member) {
            if (UserRole::Administrator === $member->getRole()) {
                $administrators[] = $member;
            } else {
                $members[] = $member;
            }

            // Every row carries its own form, because the switch saves the one
            // member it belongs to. A member who is not an administrator sees
            // no switch, so the forms are not built for them.
            if ($canAdminister) {
                $roleForms[$member->getId()] = $this->roleForm($member)->createView();
            }
        }

        return $this->render('accounts/edit.html.twig', [
            'account' => $account,
            'form' => $this->createForm(AccountType::class, $this->accountData($account)),
            'settings_form' => $this->createForm(AccountSettingsType::class, $this->settingsData($account)),
            'administrators' => $administrators,
            'members' => $members,
            'role_forms' => $roleForms,
            'can_administer' => $canAdminister,
            'has_logo' => null !== $this->attachments->blobFor($account, Attachments::LOGO),
            'join_url' => $this->joinUrl($account),
        ]);
    }

    private function roleForm(User $member): FormInterface
    {
        $data = new AccountUserRoleData();
        $data->administrator = UserRole::Administrator === $member->getRole();

        return $this->createForm(AccountUserRoleType::class, $data, [
            'action' => $this->generateUrl('account_user_update', ['id' => $member->getId()]),
        ]);
    }

    #[Route('/account', name: 'account_update', methods: ['PATCH'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER)]
    public function update(Request $request): Response
    {
        $account = $this->account();
        $form = $this->createForm(AccountType::class, $data = $this->accountData($account));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->redirectToRoute('account_edit');
        }

        $account->setName((string) $data->name);
        $this->entityManager->flush();

        if ($data->logo instanceof UploadedFile) {
            $this->attachments->replace($this->storeLogo($data->logo), $account, Attachments::LOGO);
        }

        return $this->redirectToRoute('account_edit');
    }

    #[Route('/account/settings', name: 'account_settings_update', methods: ['PATCH'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER)]
    public function updateSettings(Request $request): Response
    {
        $account = $this->account();
        $form = $this->createForm(AccountSettingsType::class, $data = $this->settingsData($account));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->administration->setRestrictRoomCreation($account, $data->restrictRoomCreationToAdministrators);
        }

        return $this->redirectToRoute('account_edit');
    }

    private function accountData(Account $account): AccountData
    {
        $data = new AccountData();
        $data->name = $account->getName();

        return $data;
    }

    private function settingsData(Account $account): AccountSettingsData
    {
        $data = new AccountSettingsData();
        $data->restrictRoomCreationToAdministrators = $account->restrictRoomCreationToAdministrators();

        return $data;
    }

    private function storeLogo(UploadedFile $logo): ActiveStorageBlob
    {
        $contents = (string) file_get_contents($logo->getPathname());

        return $this->storage->store($contents, $logo->getClientOriginalName(), $logo->getMimeType());
    }

    /**
     * The link an administrator hands out, which is the join page of the code
     * of the account.
     */
    private function joinUrl(Account $account): string
    {
        return $this->generateUrl('join', ['join_code' => $account->getJoinCode()]);
    }

    private function account(): Account
    {
        $account = $this->accounts->findOneBy([]);
        \assert(null !== $account);

        return $account;
    }
}
