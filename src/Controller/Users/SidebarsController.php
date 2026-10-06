<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Entity\Membership;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\RoomRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The room list shown next to every page.
 *
 * It is served as a Turbo Frame so it can be reloaded on its own when a room is
 * created or a membership changes.
 */
final class SidebarsController extends AbstractController
{
    /**
     * How many people without a conversation yet are offered as one click
     * entry points.
     */
    private const DIRECT_PLACEHOLDERS = 20;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly RoomRepository $rooms,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/users/me/sidebar', name: 'users_sidebar', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): Response
    {
        $direct = [];
        $shared = [];

        foreach ($this->memberships->findVisibleForUser($user) as $membership) {
            if (true === $membership->getRoom()?->isDirect()) {
                $direct[] = $membership;
            } else {
                $shared[] = $membership;
            }
        }

        // Direct conversations are ordered by activity, the others by name.
        usort($direct, static fn (Membership $a, Membership $b) => $b->getRoom()?->getUpdatedAt() <=> $a->getRoom()?->getUpdatedAt());

        return $this->render('users/sidebars/show.html.twig', [
            'direct_memberships' => $direct,
            'shared_memberships' => $shared,
            'direct_placeholder_users' => $this->directPlaceholders($user),
        ]);
    }

    /**
     * Active users who are not in a direct room with the current user yet.
     *
     * @return list<User>
     */
    private function directPlaceholders(User $user): array
    {
        $taken = [(int) $user->getId()];

        foreach ($this->rooms->findDirectRooms() as $room) {
            $members = array_filter($room->getMembers(), static fn (User $member) => $member->getId() !== $user->getId());

            if (\count($members) === \count($room->getMembers())) {
                continue;
            }

            foreach ($room->getMembers() as $member) {
                $taken[] = (int) $member->getId();
            }
        }

        $taken = array_unique($taken);
        $placeholders = [];

        foreach ($this->users->findActiveOrdered() as $candidate) {
            if (\in_array($candidate->getId(), $taken, true)) {
                continue;
            }

            $placeholders[] = $candidate;

            if (\count($placeholders) >= self::DIRECT_PLACEHOLDERS) {
                break;
            }
        }

        return $placeholders;
    }
}
