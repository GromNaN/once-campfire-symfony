<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Repository\AccountRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the current user may create an open or closed room.
 *
 * The account can be configured to reserve room creation to administrators.
 * Direct rooms are never restricted, since starting a conversation with a
 * colleague cannot fill the account with rooms.
 */
final class CreateRoomVoter extends Voter
{
    public const CAN_CREATE_ROOMS = 'CAN_CREATE_ROOMS';

    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CAN_CREATE_ROOMS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $user->isAdministrator() || true !== $this->accounts->findOneBy([])?->restrictRoomCreationToAdministrators();
    }
}
