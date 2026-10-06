<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\SearchRepository;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The actions an administrator takes on the account and its members.
 *
 * They live here rather than in the entity because they reach several tables
 * at once, and they are grouped because each one has to leave the account in a
 * state the pages can show: a deactivated member keeps no way back in, and the
 * join code an administrator regenerates is the one new members use next.
 */
final class AccountAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipRepository $memberships,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly SearchRepository $searches,
        private readonly SessionManager $sessions,
    ) {
    }

    /**
     * Takes a member out of the account for good.
     *
     * The rooms they were invited to are left, the browsers they registered are
     * dropped, and the sessions are ended so the pages stop working in a tab
     * that is still open. The address is rewritten rather than kept, which is
     * what lets the same person join again later with the same address while
     * the old account stays as it was.
     */
    public function deactivateUser(User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->memberships->deleteNonDirectFor($user);
            $this->pushSubscriptions->deleteFor($user);
            $this->searches->deleteFor($user);
            $this->sessions->endAllFor($user);

            $user->setStatus(UserStatus::Deactivated);
            $user->setEmailAddress(self::deactivatedEmailAddress($user->getEmailAddress()));

            $this->entityManager->flush();
        });
    }

    /**
     * Grants or takes away the administrator role.
     */
    public function setUserRole(User $user, UserRole $role): void
    {
        $user->setRole($role);

        $this->entityManager->flush();
    }

    public function resetJoinCode(Account $account): void
    {
        $account->resetJoinCode();

        $this->entityManager->flush();
    }

    public function setRestrictRoomCreation(Account $account, bool $restrict): void
    {
        $account->setRestrictRoomCreationToAdministrators($restrict);

        $this->entityManager->flush();
    }

    /**
     * An address that cannot be reached and cannot be typed by accident, built
     * from the old one so an administrator can still tell who it belonged to.
     */
    public static function deactivatedEmailAddress(?string $emailAddress): ?string
    {
        if (null === $emailAddress || '' === trim($emailAddress)) {
            return $emailAddress;
        }

        return str_replace('@', '-deactivated-'.Uuid::v4()->toRfc4122().'@', $emailAddress);
    }
}
