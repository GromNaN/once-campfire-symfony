<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Account;
use App\Entity\Membership;
use App\Entity\OpenRoom;
use App\Entity\User;
use App\EventListener\EarlyHintsListener;
use App\Form\Data\RegistrationData;
use App\Mercure\HubDecorator;
use App\Mercure\Topics;
use App\Service\AccountSetup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Update;

/**
 * Covers what a browser gets from the realtime layer: the Turbo Streams sent
 * through the hub, the subscriber token that authorizes them, and the early
 * hints that warm the page up.
 */
final class RealtimeTest extends DatabaseTestCase
{
    public function testTheRoomPageCarriesTheSidebarAndTheTypingNotice(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#user_sidebar');
        self::assertSelectorExists('#messages_'.$room->getId());
        self::assertSelectorExists('#typing_'.$room->getId());
    }

    public function testTheSidebarListsTheRoomsOfTheUser(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/users/me/sidebar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#shared_rooms', 'All Talk');
    }

    public function testTheSubscriberTokenIsHandedToTheBrowser(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId());

        $token = $this->cookieValue('mercureAuthorization');

        self::assertNotNull($token);
        // The token is a JWT, so the hub can verify the grants it carries.
        self::assertCount(3, explode('.', $token));
    }

    public function testTheStreamSourceAsksForTheTopicsOfTheReader(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $alice = $this->findUser('alice@example.com');
        $id = (int) $alice->getId();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());

        $source = $crawler->filter('turbo-mercure-stream-source');
        self::assertCount(1, $source);

        // The hub refuses a subscription that names no topic, so the address
        // carries the topics of the reader.
        $src = (string) $source->attr('src');
        self::assertStringContainsString('topic='.rawurlencode(Topics::ROOMS), $src);
        self::assertStringContainsString('topic='.rawurlencode(Topics::userRooms($id)), $src);
        self::assertStringContainsString('topic='.rawurlencode(Topics::userUnreads($id)), $src);
        self::assertStringContainsString('topic='.rawurlencode(Topics::roomMessages((int) $room->getId())), $src);
        self::assertStringContainsString('topic='.rawurlencode(Topics::roomTyping((int) $room->getId())), $src);

        // The token that grants those topics travels with the request, which is
        // what makes the subscription private.
        self::assertNotNull($source->attr('private'));
    }

    public function testPostingAMessageIsBroadcastToTheRoom(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => 'Hello everyone',
            'message[clientMessageId]' => 'b7c1c6f0-0000-4000-8000-000000000001',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        $update = $this->lastUpdateOn(Topics::roomMessages((int) $room->getId()));

        self::assertNotNull($update);
        self::assertStringContainsString('message_b7c1c6f0-0000-4000-8000-000000000001', $update->getData());
        self::assertStringContainsString('Hello everyone', $update->getData());
    }

    public function testTypingIsBroadcastToTheRoom(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('POST', '/rooms/'.$room->getId().'/typing', ['action' => 'start']);

        self::assertResponseStatusCodeSame(204);

        $update = $this->lastUpdateOn(Topics::roomTyping((int) $room->getId()));

        self::assertNotNull($update);
        self::assertStringContainsString('Alice', $update->getData());
    }

    public function testAMessageLeavesTheRoomUnreadForTheOtherMembers(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $bob = $this->addMember('Bob', 'bob@example.com');
        $membership = $this->entityManager()->getRepository(Membership::class)->findOneBy([
            'room' => $room,
            'user' => $bob,
        ]);
        self::assertNotNull($membership);
        self::assertNull($membership->getUnreadAt());

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => 'Anyone around?',
            'message[clientMessageId]' => 'b7c1c6f0-0000-4000-8000-000000000002',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        $membership = $this->entityManager()->getRepository(Membership::class)->findOneBy([
            'room' => $room,
            'user' => $bob,
        ]);
        self::assertNotNull($membership);
        self::assertNotNull($membership->getUnreadAt());

        // The other member is told on their own topic, not on the room one.
        self::assertNotNull($this->lastUpdateOn(Topics::userUnreads((int) $bob->getId())));
    }

    public function testAPageAnswersWithEarlyHints(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $request = Request::create('/rooms/'.$room->getId());
        $request->attributes->set('_route', 'rooms_show');

        $response = $this->earlyHints()->hintsFor($request);

        self::assertNotNull($response);
        self::assertSame(103, $response->getStatusCode());
        self::assertStringContainsString('rel="preload"', (string) $response->headers->get('Link'));
    }

    public function testAFragmentRequestGetsNoEarlyHints(): void
    {
        $request = Request::create('/users/me/sidebar');
        $request->attributes->set('_route', 'users_sidebar');
        $request->headers->set('Turbo-Frame', 'user_sidebar');

        self::assertNull($this->earlyHints()->hintsFor($request));
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    /**
     * Adds a member without signing the current browser in as them, so that the
     * administrator stays the one posting.
     */
    private function addMember(string $name, string $emailAddress): User
    {
        $data = new RegistrationData();
        $data->name = $name;
        $data->emailAddress = $emailAddress;
        $data->password = 'correct horse battery';

        static::getContainer()->get(AccountSetup::class)->createMember($data);

        return $this->findUser($emailAddress);
    }

    private function earlyHints(): EarlyHintsListener
    {
        return static::getContainer()->get(EarlyHintsListener::class);
    }

    private function hub(): HubDecorator
    {
        return static::getContainer()->get(HubDecorator::class);
    }

    private function lastUpdateOn(string $topic): ?Update
    {
        $found = null;

        foreach ($this->hub()->getPublishedUpdates() as $update) {
            if (\in_array($topic, $update->getTopics(), true)) {
                $found = $update;
            }
        }

        return $found;
    }

    private function cookieValue(string $name): ?string
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        return null;
    }
}
