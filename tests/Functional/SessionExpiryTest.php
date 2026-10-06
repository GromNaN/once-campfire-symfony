<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Session;

/**
 * Covers the two ways a device is signed out on its own: it was left idle for
 * longer than the session cookie lives, or it has been signed in for longer
 * than the absolute limit however often it was used.
 */
final class SessionExpiryTest extends DatabaseTestCase
{
    public function testAnActiveDeviceStaysSignedIn(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/users/me/profile');

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->session());
    }

    public function testAnIdleDeviceIsSignedOut(): void
    {
        $this->runFirstRun();

        // The device was last used well past the idle timeout.
        $session = $this->session();
        self::assertNotNull($session);
        $session->setLastActiveAt(new \DateTimeImmutable('-30 days'));
        $this->entityManager()->flush();

        $this->client->request('GET', '/users/me/profile');

        self::assertResponseRedirects('/session/new');
        self::assertNull($this->session());
    }

    public function testADeviceSignedInForTooLongIsSignedOutEvenIfItIsUsed(): void
    {
        $this->runFirstRun();

        // The device is used right now, but the sign in itself is too old.
        $session = $this->session();
        self::assertNotNull($session);
        $session->setCreatedAt(new \DateTimeImmutable('-60 days'));
        $this->entityManager()->flush();

        $this->client->request('GET', '/users/me/profile');

        self::assertResponseRedirects('/session/new');
        self::assertNull($this->session());
    }

    private function session(): ?Session
    {
        return $this->entityManager()->getRepository(Session::class)->findOneBy([]);
    }
}
