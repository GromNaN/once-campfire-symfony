<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\UserStatus;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Message\MessageWriter;
use App\Service\AccountSetup;

/**
 * Covers banning a member and lifting the ban, the way an administrator walks
 * them: the button on the page of a member, and what the server does when the
 * form is sent.
 */
final class BansTest extends DatabaseTestCase
{
    /**
     * The address the banned member is signed in from. It is not the one the
     * administrator uses, so that a ban on one does not hide the requests of
     * the other.
     */
    private const MEMBER_ADDRESS = '10.0.0.5';

    private const ADMINISTRATOR_ADDRESS = '127.0.0.1';

    public function testBanningAMemberEndsTheirSessionsRemovesTheirMessagesAndRefusesTheirAddress(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $alice = $this->findUser('alice@example.com');
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->postMessage($room, $alice, 'Welcome aboard');
        $this->postMessage($room, $bob, 'Thanks everyone');

        $this->signInAs('bob@example.com', self::MEMBER_ADDRESS);
        self::assertSame(1, $this->sessionCount($bob));
        $this->signInAs('alice@example.com');

        $crawler = $this->client->request('GET', '/users/'.$bob->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form[action$="/ban"] button', 'Ban Bob');

        $this->client->submit($crawler->selectButton('Ban Bob')->form());

        self::assertResponseRedirects('/users/'.$bob->getId());

        $this->entityManager()->clear();
        $banned = $this->user($bob->getId());

        // The member is refused, the addresses they used are recorded, and the
        // sessions they left open are gone.
        self::assertSame(UserStatus::Banned, $banned->getStatus());
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM bans WHERE ip_address = ? AND user_id = ?',
            [self::MEMBER_ADDRESS, $bob->getId()],
        ));
        self::assertSame(0, $this->sessionCount($banned));

        // What the member wrote is gone; what everyone else wrote stays.
        self::assertSame(0, $this->messagesOf($banned));
        self::assertSame(1, $this->messagesOf($alice));
    }

    public function testLiftingABanLetsTheMemberBackIn(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->signInAs('bob@example.com', self::MEMBER_ADDRESS);
        $this->signInAs('alice@example.com');

        $crawler = $this->client->request('GET', '/users/'.$bob->getId());
        $this->client->submit($crawler->selectButton('Ban Bob')->form());
        self::assertResponseRedirects('/users/'.$bob->getId());

        // The page of a banned member still shows them, marked as banned, with
        // the way back.
        $crawler = $this->client->request('GET', '/users/'.$bob->getId());

        self::assertSelectorExists('.banned');
        self::assertSelectorTextContains('form[action$="/ban"] button', 'Remove ban');

        $this->client->submit($crawler->selectButton('Remove ban')->form());

        self::assertResponseRedirects('/users/'.$bob->getId());

        $this->entityManager()->clear();
        $lifted = $this->user($bob->getId());

        self::assertSame(UserStatus::Active, $lifted->getStatus());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM bans'));

        // The page offers the ban again, and no longer marks the member.
        $this->client->request('GET', '/users/'.$bob->getId());

        self::assertSelectorNotExists('.banned');
        self::assertSelectorTextContains('form[action$="/ban"] button', 'Ban Bob');
    }

    public function testAMemberIsNotOfferedTheBanButtonAndCannotBanAnyone(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $carol = $this->addMember('Carol', 'carol@example.com');

        $this->signInAs('bob@example.com');

        // Nothing on the page of another member offers to ban them.
        $this->client->request('GET', '/users/'.$carol->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/ban"]');

        // A request forged by hand is refused before the token is even read,
        // because the member may not administer the account.
        $this->client->request('POST', '/users/'.$carol->getId().'/ban', ['_csrf_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);

        $this->entityManager()->clear();
        self::assertSame(UserStatus::Active, $this->user($carol->getId())->getStatus());
    }

    public function testAnAdministratorCannotBanThemselves(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');
        $bob = $this->addMember('Bob', 'bob@example.com');

        // The page hands out a token that is valid for banning a member; it is
        // used against the administrator themselves, which the server has to
        // refuse on its own.
        $crawler = $this->client->request('GET', '/users/'.$bob->getId());
        $token = $crawler->filter('form[action$="/ban"] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/users/'.$alice->getId().'/ban', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(403);

        $this->entityManager()->clear();
        self::assertSame(UserStatus::Active, $this->user($alice->getId())->getStatus());
    }

    public function testABannedAddressIsRefusedWhenItTriesToActButMayStillRead(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->signInAs('bob@example.com', self::MEMBER_ADDRESS);
        $this->signInAs('alice@example.com');

        $crawler = $this->client->request('GET', '/users/'.$bob->getId());
        $this->client->submit($crawler->selectButton('Ban Bob')->form());
        self::assertResponseRedirects('/users/'.$bob->getId());

        // Reading stays allowed, so the banned visitor still reaches the sign
        // in page instead of being locked out of the application entirely.
        $this->client->request('GET', '/session/new', [], [], ['REMOTE_ADDR' => self::MEMBER_ADDRESS]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/session', [], [], ['REMOTE_ADDR' => self::MEMBER_ADDRESS]);
        self::assertResponseStatusCodeSame(429);
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    private function postMessage(Room $room, User $creator, string $body): Message
    {
        return static::getContainer()->get(MessageWriter::class)->create($room, $creator, $body);
    }

    private function addMember(string $name, string $emailAddress): User
    {
        $data = new RegistrationData();
        $data->name = $name;
        $data->emailAddress = $emailAddress;
        $data->password = 'correct horse battery';

        static::getContainer()->get(AccountSetup::class)->createMember($data);

        return $this->findUser($emailAddress);
    }

    private function user(?int $id): User
    {
        $user = $this->entityManager()->getRepository(User::class)->find($id);
        self::assertNotNull($user);

        return $user;
    }

    private function sessionCount(User $user): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM sessions WHERE user_id = ?',
            [$user->getId()],
        );
    }

    private function messagesOf(User $user): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM messages WHERE creator_id = ?',
            [$user->getId()],
        );
    }

    /**
     * Signs a user in from a given address.
     *
     * The address is set on the client rather than passed to a single request,
     * because the session records it when it starts, which is a request of its
     * own. Every call names its address, so a test never inherits the one of
     * the call before it.
     */
    private function signInAs(string $emailAddress, string $address = self::ADMINISTRATOR_ADDRESS): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', $address);

        $crawler = $this->client->request('GET', '/session/new');
        $this->client->submit($crawler->selectButton('Go')->form([
            'login[emailAddress]' => $emailAddress,
            'login[password]' => 'correct horse battery',
        ]));

        self::assertResponseRedirects('/');
    }
}
