<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActionText\SignedId;
use App\Entity\ClosedRoom;
use App\Entity\DirectRoom;
use App\Entity\Enum\MembershipInvolvement;
use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\PushSubscription;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Message\MessageWriter;
use App\Push\WebPushSender;
use App\Service\AccountSetup;
use App\Tests\Support\RecordingPushSender;

/**
 * Covers push notifications: who is told about a message, what the
 * notification says, and the devices a reader registers.
 *
 * What a notification says is checked against a sender that records it rather
 * than against the request it produced, because the payload is encrypted on
 * the way out.
 */
final class PushTest extends DatabaseTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';
    private const OTHER_ENDPOINT = 'https://updates.push.services.mozilla.com/wpush/v2/def456';

    /**
     * The example key pair of the Web Push documentation, which is the shape a
     * browser hands out when it subscribes.
     */
    private const P256DH = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
    private const AUTH = 'tBHItJI5svbpez7KI4CCXg';

    private RecordingPushSender $pushes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pushes = new RecordingPushSender();
    }

    public function testAMessageReachesTheDeviceOfAMemberWhoAskedForEverything(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        self::assertSame([self::ENDPOINT], $this->pushes->endpoints());
    }

    public function testAMemberWhoAskedForMentionsIsToldWhenTheyAreNamed(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Mentions);
        $this->subscribe($bob);

        $this->post($room, $this->findUser('alice@example.com'), 'Hello '.$this->mention($bob));

        self::assertSame([self::ENDPOINT], $this->pushes->endpoints());
    }

    public function testAMemberWhoAskedForMentionsIsNotToldAboutOtherMessages(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Mentions);
        $this->subscribe($bob);

        $this->post($room, $this->findUser('alice@example.com'), 'Nothing for Bob');

        self::assertSame([], $this->pushes->endpoints());
    }

    public function testAMemberWhoTurnedNotificationsOffIsNotTold(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Nothing);
        $this->subscribe($bob);

        $this->post($room, $this->findUser('alice@example.com'), 'Hello '.$this->mention($bob));

        self::assertSame([], $this->pushes->endpoints());
    }

    public function testAMemberReadingTheRoomIsNotTold(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $membership = $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);

        // A reader with the room on their screen has nothing to be told about.
        $membership->present();
        $this->entityManager()->flush();

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        self::assertSame([], $this->pushes->endpoints());
    }

    public function testTheAuthorIsNotToldAboutTheirOwnMessage(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $alice = $this->findUser('alice@example.com');
        $this->involve($room, $alice, MembershipInvolvement::Everything);
        $this->subscribe($alice);

        $this->post($room, $alice, 'I said this one myself');

        self::assertSame([], $this->pushes->endpoints());
    }

    public function testASharedRoomNamesTheRoomAndADirectRoomNamesTheAuthor(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $alice = $this->findUser('alice@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);

        $this->post($room, $alice, 'Deploy please');

        $payload = $this->pushes->lastPayload();
        self::assertNotNull($payload);
        self::assertSame('All Talk', $payload->title);
        self::assertSame('Alice: Deploy please', $payload->body);
        self::assertSame('/rooms/'.$room->getId(), $payload->path);
        self::assertSame('/account/logo', $payload->icon);

        // A direct conversation has no name of its own, so the notification
        // says who wrote rather than where.
        $direct = $this->directRoom($alice, $bob);
        $this->post($direct, $alice, 'Are you there?');

        $payload = $this->pushes->lastPayload();
        self::assertNotNull($payload);
        self::assertSame('Alice', $payload->title);
        self::assertSame('Are you there?', $payload->body);
    }

    public function testALongMessageIsSentWhole(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);

        // The original application puts the whole text of the message on the
        // lock screen, so nothing is cut short here either.
        $body = str_repeat('a', 400);
        $this->post($room, $this->findUser('alice@example.com'), $body);

        $payload = $this->pushes->lastPayload();
        self::assertNotNull($payload);
        self::assertSame('Alice: '.$body, $payload->body);
    }

    public function testTheBadgeCountsTheRoomsTheReaderHasNotCaughtUpWith(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $alice = $this->findUser('alice@example.com');
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);

        $other = $this->closedRoom('Secret', $alice, $bob);
        $this->involve($other, $bob, MembershipInvolvement::Everything);
        $this->membership($other, $bob)->setUnreadAt(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->entityManager()->flush();

        $this->post($room, $alice, 'Deploy please');

        // The message just posted leaves the first room unread as well, so the
        // badge counts both rooms.
        self::assertSame(2, $this->pushes->lastPayload()?->badge);
    }

    public function testOneNotificationIsSentHoweverManyBrowsersTheReaderRegistered(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);
        $this->subscribe($bob, self::OTHER_ENDPOINT);

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        // Both devices are reached, and the badge is counted once for the
        // reader rather than once for each of them.
        self::assertSame([self::ENDPOINT, self::OTHER_ENDPOINT], $this->pushes->endpoints());
        self::assertSame(1, $this->pushes->count());
        self::assertSame(1, $this->pushes->lastPayload()?->badge);
    }

    public function testARegisteredDeviceIsStored(): void
    {
        $this->runFirstRun();

        $this->client->request('POST', '/users/me/push_subscriptions', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'Test browser',
        ], json_encode([
            'endpoint' => self::ENDPOINT,
            'p256dh_key' => self::P256DH,
            'auth_key' => self::AUTH,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        $subscription = $this->entityManager()->getRepository(PushSubscription::class)->findOneBy([]);
        self::assertNotNull($subscription);
        self::assertSame(self::ENDPOINT, $subscription->getEndpoint());
        self::assertSame(self::P256DH, $subscription->getP256dhKey());
        self::assertSame(self::AUTH, $subscription->getAuthKey());
        self::assertSame('Test browser', $subscription->getUserAgent());
    }

    public function testRegisteringTheSameDeviceTwiceKeepsOneRow(): void
    {
        $this->runFirstRun();
        $body = json_encode([
            'endpoint' => self::ENDPOINT,
            'p256dh_key' => self::P256DH,
            'auth_key' => self::AUTH,
        ], \JSON_THROW_ON_ERROR);

        // A browser registers again on every visit, so the second call must
        // not leave a second row behind.
        $this->client->request('POST', '/users/me/push_subscriptions', [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/users/me/push_subscriptions', [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $this->entityManager()->getRepository(PushSubscription::class)->findAll());
    }

    public function testReRegisteringADeviceStoredBeforeTheRulesIsRefused(): void
    {
        $this->runFirstRun();

        // A device that was stored before the endpoint rules existed, kept
        // alive by a browser that asks again: the rules of today decide, so the
        // row is not touched and the browser is told.
        $this->subscribe($this->findUser('alice@example.com'), 'https://attacker.example.com/steal');

        $this->client->request('POST', '/users/me/push_subscriptions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'endpoint' => 'https://attacker.example.com/steal',
            'p256dh_key' => self::P256DH,
            'auth_key' => self::AUTH,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager()->getRepository(PushSubscription::class)->findAll());
    }

    public function testAnEndpointThatIsNotAPushServiceIsRefused(): void
    {
        $this->runFirstRun();

        $this->client->request('POST', '/users/me/push_subscriptions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'endpoint' => 'https://example.com/push/1',
            'p256dh_key' => self::P256DH,
            'auth_key' => self::AUTH,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager()->getRepository(PushSubscription::class)->findAll());
    }

    public function testThePageListsTheDevicesOfTheReader(): void
    {
        $this->runFirstRun();
        $this->subscribe($this->findUser('alice@example.com'));

        $this->client->request('GET', '/users/me/push_subscriptions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#push_subscriptions', self::ENDPOINT);
    }

    public function testAReaderDropsTheirOwnDevice(): void
    {
        $this->runFirstRun();
        $this->subscribe($this->findUser('alice@example.com'));

        $crawler = $this->client->request('GET', '/users/me/push_subscriptions');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('#push_subscriptions form')->last()->form());

        self::assertResponseRedirects('/users/me/push_subscriptions');
        self::assertCount(0, $this->entityManager()->getRepository(PushSubscription::class)->findAll());
    }

    public function testAReaderCannotDropTheDeviceOfSomeoneElse(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');
        $aliceDevice = $this->subscribe($alice);

        $bob = $this->addMember('Bob', 'bob@example.com');
        $bobDevice = $this->subscribe($bob);

        // The devices are in place before the sign in, because a request
        // detaches the entities read before it.
        $this->signOut();
        $this->signIn('bob@example.com');

        $crawler = $this->client->request('GET', '/users/me/push_subscriptions');
        self::assertResponseIsSuccessful();

        // The page only offers the device of Bob, and the token below is the
        // one it hands out. The address is aimed at the device of Alice
        // instead, so only the scoping of the request to the reader can keep
        // that device alive.
        $token = $crawler
            ->filter('form[action$="/push_subscriptions/'.$bobDevice->getId().'"] input[name="_csrf_token"]')
            ->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/users/me/push_subscriptions/'.$aliceDevice->getId(), [
            '_method' => 'DELETE',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/users/me/push_subscriptions');
        self::assertCount(2, $this->entityManager()->getRepository(PushSubscription::class)->findAll());
    }

    public function testATestNotificationReachesTheDevice(): void
    {
        $this->runFirstRun();
        $this->subscribe($this->findUser('alice@example.com'));

        // The sender is replaced inside the request that sends the
        // notification, so the kernel is kept from rebooting under it.
        $this->client->disableReboot();
        static::getContainer()->set(WebPushSender::class, $this->pushes);

        $crawler = $this->client->request('GET', '/users/me/push_subscriptions');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('#push_subscriptions form')->first()->form());

        self::assertResponseRedirects('/users/me/push_subscriptions');
        self::assertSame([self::ENDPOINT], $this->pushes->endpoints());
        self::assertSame('Campfire Test', $this->pushes->lastPayload()?->title);
    }

    public function testTheManifestNamesTheAccountAndItsIcons(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/webmanifest');

        self::assertResponseIsSuccessful();
        self::assertSame('application/manifest+json', $this->client->getResponse()->headers->get('Content-Type'));

        $manifest = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('Campfire', $manifest['name']);
        self::assertSame('/', $manifest['start_url']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame(
            ['/account/logo?size=small', '/account/logo', '/account/logo'],
            array_column($manifest['icons'], 'src'),
        );
    }

    public function testTheApplicationReportsItsHealth(): void
    {
        $this->client->request('GET', '/up');

        self::assertResponseIsSuccessful();
        self::assertSame('OK', $this->client->getResponse()->getContent());
    }

    public function testTheServiceWorkerIsServedFromTheRootOfTheSite(): void
    {
        // A worker only controls the pages under the path it is served from,
        // so it is a file of the document root rather than an asset.
        $path = static::getContainer()->getParameter('kernel.project_dir').'/public/service-worker.js';

        self::assertFileExists($path);
        self::assertStringContainsString('addEventListener("push"', (string) file_get_contents($path));
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    private function closedRoom(string $name, User $creator, ?User $member = null): ClosedRoom
    {
        $entityManager = $this->entityManager();

        $room = new ClosedRoom();
        $room->setName($name);
        $room->setCreator($creator);

        $entityManager->persist($room);
        $entityManager->persist($room->addMember($creator));

        if (null !== $member) {
            $entityManager->persist($room->addMember($member));
        }

        $entityManager->flush();

        return $room;
    }

    private function directRoom(User $first, User $second): DirectRoom
    {
        $entityManager = $this->entityManager();

        $room = new DirectRoom();
        $room->setCreator($first);

        $entityManager->persist($room);
        $entityManager->persist($room->addMember($first));
        $entityManager->persist($room->addMember($second));
        $entityManager->flush();

        return $room;
    }

    /**
     * Posts a message the way the application does, which is what asks for the
     * notification to be sent.
     *
     * The recording sender goes in just before, because a request made earlier
     * in the test reboots the kernel and takes the container with it.
     */
    private function post(Room $room, User $creator, string $body): Message
    {
        $container = static::getContainer();

        // A second post in the same test finds the sender already built, and a
        // built service cannot be replaced.
        if (!$container->initialized(WebPushSender::class)) {
            $container->set(WebPushSender::class, $this->pushes);
        }

        return $container->get(MessageWriter::class)->create($room, $creator, $body);
    }

    private function subscribe(User $user, string $endpoint = self::ENDPOINT): PushSubscription
    {
        $entityManager = $this->entityManager();

        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setEndpoint($endpoint);
        $subscription->setP256dhKey(self::P256DH);
        $subscription->setAuthKey(self::AUTH);
        $subscription->setUserAgent('Test browser');

        $entityManager->persist($subscription);
        $entityManager->flush();

        return $subscription;
    }

    private function involve(Room $room, User $user, MembershipInvolvement $involvement): Membership
    {
        $membership = $this->membership($room, $user);
        $membership->setInvolvement($involvement);
        $this->entityManager()->flush();

        return $membership;
    }

    private function membership(Room $room, User $user): Membership
    {
        $membership = $this->entityManager()->getRepository(Membership::class)->findOneBy([
            'room' => $room,
            'user' => $user,
        ]);
        self::assertNotNull($membership);

        return $membership;
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

    private function signIn(string $emailAddress): void
    {
        $crawler = $this->client->request('GET', '/session/new');
        $this->client->submit($crawler->selectButton('Go')->form([
            'login[emailAddress]' => $emailAddress,
            'login[password]' => 'correct horse battery',
        ]));

        self::assertResponseRedirects('/');
    }

    /**
     * The attachment element a body keeps for a mention.
     */
    private function mention(User $user): string
    {
        $sgid = static::getContainer()->get(SignedId::class)
            ->encode('User', (int) $user->getId(), SignedId::PURPOSE_ATTACHABLE);

        return \sprintf('<action-text-attachment sgid="%s" content-type="application/vnd.campfire.mention"></action-text-attachment>', $sgid);
    }
}
