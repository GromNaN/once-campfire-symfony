<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ban;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Message\RemoveBannedContent;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Banning a member and lifting the ban.
 *
 * The work reaches several tables at once: the addresses the member was seen
 * from are recorded so the listener refuses them, their sessions are ended so
 * the pages stop working in the tabs they left open, and the messages they
 * wrote are queued for removal. That is the same set of steps the original
 * application runs, except for closing the open connections of the banned
 * browser, which has no equivalent here: the sessions are gone, so the next
 * request the browser makes signs it out.
 */
final class BanAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SessionManager $sessions,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Refuses a member and everything they wrote.
     *
     * The addresses come from the devices they were signed in on, so the ban
     * follows the person rather than only the account they used. Removing the
     * messages is slow and reaches every room they were in, so it is left to
     * the worker.
     */
    public function ban(User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->recordAddresses($user);
            $this->sessions->endAllFor($user);

            $user->setStatus(UserStatus::Banned);

            $this->entityManager->flush();
        });

        $this->bus->dispatch(new RemoveBannedContent((int) $user->getId()));
    }

    /**
     * Lets a banned member back in.
     *
     * The addresses are forgotten and the account is active again. What the
     * ban deleted stays deleted, which is what the original does too.
     */
    public function unban(User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            foreach ($user->getBans() as $ban) {
                $this->entityManager->remove($ban);
            }

            $user->setStatus(UserStatus::Active);

            $this->entityManager->flush();
        });
    }

    /**
     * Records the address of every device the member was signed in on, once
     * each, and leaves out the sessions that carried no address.
     */
    private function recordAddresses(User $user): void
    {
        $addresses = [];

        foreach ($user->getSessions() as $session) {
            $address = $session->getIpAddress();

            if (null !== $address && '' !== trim($address)) {
                $addresses[$address] = true;
            }
        }

        foreach (array_keys($addresses) as $address) {
            $ban = new Ban();
            $ban->setIpAddress($address);
            $ban->setUser($user);

            $this->entityManager->persist($ban);
        }
    }
}
