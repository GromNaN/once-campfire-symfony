<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides whether the current user may read a message.
 *
 * Reading a message is reading the room it belongs to, so membership is what
 * the voter asks for. An account administrator who is not a member of the room
 * is turned down: a closed room stays closed, even for them.
 */
final class ViewMessageVoter extends Voter
{
    public const CAN_VIEW = 'CAN_VIEW';

    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CAN_VIEW === $attribute && $subject instanceof Message;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Message);

        $user = $token->getUser();
        $room = $subject->getRoom();

        return $user instanceof User
            && null !== $room
            && null !== $this->memberships->findFor($room, $user);
    }
}
