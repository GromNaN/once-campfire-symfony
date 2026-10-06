<?php

declare(strict_types=1);

namespace App\Twig;

use App\ActiveStorage\Attachments;
use App\Entity\Account;
use App\Entity\User;
use App\Http\LastRoom;
use App\Repository\AccountRepository;
use App\Room\LastVisitedRoom;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The pieces of the page shell that several pages need.
 *
 * The navigation bar of a page that is not a room carries a way back, and the
 * body of the page carries classes that describe who is reading it and whether
 * the account has a picture of its own. The original application computes all
 * of these in a helper, so they are gathered here as well.
 */
final class LayoutExtension
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly Attachments $attachments,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly LastVisitedRoom $lastRoom,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $appVersion,
    ) {
    }

    /**
     * Whether the account has a picture of its own.
     *
     * The stylesheets use this to leave room for the picture in the navigation
     * bar, so the page has to say it before the picture is drawn.
     */
    #[AsTwigFunction('account_has_logo')]
    public function accountHasLogo(): bool
    {
        $account = $this->accounts->findOneBy([]);

        return $account instanceof Account && null !== $this->attachments->blobFor($account, Attachments::LOGO);
    }

    /**
     * Where the back button of a page leads.
     *
     * The page the reader came from, unless there is none or it is the page
     * itself, in which case the root of the application is the only sensible
     * answer.
     */
    #[AsTwigFunction('link_back_url')]
    public function linkBackUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $referer = $request?->headers->get('referer');

        if (null === $request || null === $referer || '' === $referer || $referer === $request->getUri()) {
            return $this->urlGenerator->generate('root');
        }

        return $referer;
    }

    /**
     * Where the back button leads when it should return to the conversation
     * the reader was in, which is the root when they have not opened one yet.
     */
    #[AsTwigFunction('last_room_url')]
    public function lastRoomUrl(): string
    {
        $user = $this->currentUser();

        if (null === $user) {
            return $this->urlGenerator->generate('root');
        }

        $room = $this->lastRoom->for($user, $this->requestStack->getCurrentRequest()?->cookies->get(LastRoom::COOKIE));

        return null === $room
            ? $this->urlGenerator->generate('root')
            : $this->urlGenerator->generate('rooms_show', ['id' => $room->getId()]);
    }

    /**
     * The address that lets someone join the account.
     *
     * It is handed out on the account page and in the welcome message of the
     * first room, so it is read from the account rather than passed around.
     */
    #[AsTwigFunction('join_url')]
    public function joinUrl(): string
    {
        $account = $this->accounts->findOneBy([]);

        return $account instanceof Account
            ? $this->urlGenerator->generate('join', ['join_code' => $account->getJoinCode()])
            : $this->urlGenerator->generate('first_run');
    }

    /**
     * The version of the application, shown in the footer of the account page.
     */
    #[AsTwigFunction('version_badge')]
    public function versionBadge(): string
    {
        return '' === $this->appVersion ? 'development' : $this->appVersion;
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}
