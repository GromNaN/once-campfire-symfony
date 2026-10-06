<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the current user may administer a record.
 *
 * An account administrator may administer anything, a member may administer
 * the records they created, and a record that is not saved yet may be
 * administered by whoever is creating it.
 */
final class AdministerVoter extends Voter
{
    public const CAN_ADMINISTER = 'CAN_ADMINISTER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CAN_ADMINISTER === $attribute && ($subject instanceof Room || $subject instanceof Message);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            $vote?->addReason('The token holds no user.');

            return false;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        if (null === $subject->getId()) {
            // A record that is not saved yet belongs to whoever is creating it.
            return true;
        }

        if ($subject instanceof Room && $subject->isDirect()) {
            // A direct conversation gathers people on the same footing, so
            // anyone in it may change it or delete it.
            return $user->isIn($subject);
        }

        return $user->getId() === $subject->getCreator()?->getId();
    }
}
