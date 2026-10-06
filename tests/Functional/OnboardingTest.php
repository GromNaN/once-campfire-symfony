<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Account;
use App\Entity\Enum\UserRole;
use App\Entity\Membership;
use App\Entity\OpenRoom;
use App\Entity\User;

/**
 * Covers the first run, the join page and signing in and out.
 */
final class OnboardingTest extends DatabaseTestCase
{
    public function testSignInPageSendsTheVisitorToTheFirstRunPageWhenNoUserExists(): void
    {
        $this->client->request('GET', '/session/new');

        self::assertResponseRedirects('/first_run');
    }

    public function testFirstRunCreatesTheAccountItsFirstRoomAndTheAdministrator(): void
    {
        $this->runFirstRun();

        $entityManager = $this->entityManager();

        $account = $entityManager->getRepository(Account::class)->findOneBy([]);
        self::assertNotNull($account);
        self::assertSame('Campfire', $account->getName());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{4}(-[A-Za-z0-9]{4}){2}$/', $account->getJoinCode());

        $user = $this->findUser('alice@example.com');
        self::assertSame(UserRole::Administrator, $user->getRole());

        $room = $entityManager->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);
        self::assertSame('All Talk', $room->getName());
        self::assertSame($user->getId(), $room->getCreator()?->getId());
        self::assertSame([$user->getId()], $this->memberIds($room));
    }

    public function testTheRootPageSendsTheUserToTheirRoom(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/');

        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);
        self::assertResponseRedirects('/rooms/'.$room->getId());
    }

    public function testTheRootPageShowsTheEmptyStateWhenTheUserHasNoRoom(): void
    {
        $this->runFirstRun();

        // Removing the memberships leaves the account in place but the user
        // without a room, which is the state the empty page describes.
        $entityManager = $this->entityManager();
        foreach ($entityManager->getRepository(Membership::class)->findAll() as $membership) {
            $entityManager->remove($membership);
        }
        $entityManager->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message-area .message-area--empty');
        self::assertSelectorTextContains('#message-area .for-screen-reader', 'Alice');
    }

    public function testSigningInAndOut(): void
    {
        $this->runFirstRun();
        $this->signOut();

        $crawler = $this->client->request('GET', '/session/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Go')->form([
            'login[emailAddress]' => 'alice@example.com',
            'login[password]' => 'correct horse battery',
        ]));

        self::assertResponseRedirects('/');

        $this->signOut();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/session/new');
    }

    public function testSigningInWithAWrongPasswordIsRejected(): void
    {
        $this->runFirstRun();
        $this->signOut();

        $crawler = $this->client->request('GET', '/session/new');
        $this->client->submit($crawler->selectButton('Go')->form([
            'login[emailAddress]' => 'alice@example.com',
            'login[password]' => 'wrong password',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testJoiningWithAnInvalidCodeIsNotFound(): void
    {
        $this->runFirstRun();
        $this->signOut();

        $this->client->request('GET', '/join/AAAA-BBBB-CCCC');

        self::assertResponseStatusCodeSame(404);
    }

    public function testJoiningWithTheAccountCodeCreatesAMember(): void
    {
        $this->runFirstRun();
        $this->signOut();

        $account = $this->entityManager()->getRepository(Account::class)->findOneBy([]);
        self::assertNotNull($account);

        $crawler = $this->client->request('GET', '/join/'.$account->getJoinCode());
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form([
            'registration[name]' => 'Bob',
            'registration[emailAddress]' => 'bob@example.com',
            'registration[password]' => 'correct horse battery',
        ]));

        self::assertResponseRedirects('/');

        $bob = $this->findUser('bob@example.com');
        self::assertSame(UserRole::Member, $bob->getRole());

        // A new member is granted access to every open room.
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);
        self::assertContains($bob->getId(), $this->memberIds($room));
    }

    /**
     * @return list<int|null>
     */
    private function memberIds(OpenRoom $room): array
    {
        return array_map(static fn (User $member) => $member->getId(), $room->getMembers());
    }
}
