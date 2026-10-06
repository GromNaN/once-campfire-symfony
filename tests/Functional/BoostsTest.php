<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Boost;
use App\Entity\ClosedRoom;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Service\AccountSetup;

/**
 * Covers the boosts of a message the way a browser walks them: read the list,
 * add one through the form, and take one back.
 *
 * A boost belongs to the person who added it, so the tests also walk the
 * answers a member gets for the boost of someone else and for a message of a
 * room they are not in.
 */
final class BoostsTest extends DatabaseTestCase
{
    public function testAMessageShowsTheBoostsItWasGiven(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $this->addBoost($message, $this->findUser('alice@example.com'), 'Nice');

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#boosts_'.$message->getKey(), 'Nice');
        // The booster is named on the avatar rather than written beside the
        // boost, which is what a screen reader reads out.
        self::assertSelectorExists('#boosts_'.$message->getKey().' img[aria-label*="Alice"]');
    }

    public function testAMemberAddsABoostThroughTheForm(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');
        $alice = $this->findUser('alice@example.com');

        $crawler = $this->client->request('GET', '/messages/'.$message->getId().'/boosts/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Submit')->form([
            'boost[content]' => 'On it',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        // The form asks for the list back, so the answer is a redirect to it.
        self::assertResponseRedirects('/messages/'.$message->getId().'/boosts');

        $boost = $this->entityManager()->getRepository(Boost::class)->findOneBy(['message' => $message]);
        self::assertNotNull($boost);
        self::assertSame('On it', $boost->getContent());
        self::assertSame($alice->getId(), $boost->getBooster()?->getId());

        $this->client->request('GET', '/messages/'.$message->getId().'/boosts');
        self::assertSelectorTextContains('#boosts_'.$message->getKey(), 'On it');
    }

    public function testABoostWithoutTextIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $crawler = $this->client->request('GET', '/messages/'.$message->getId().'/boosts/new');
        $this->client->submit($crawler->selectButton('Submit')->form([
            'boost[content]' => '',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->boostsOf($message));
    }

    public function testABoostLongerThanTheColumnIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $crawler = $this->client->request('GET', '/messages/'.$message->getId().'/boosts/new');
        $this->client->submit($crawler->selectButton('Submit')->form([
            'boost[content]' => str_repeat('a', 17),
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->boostsOf($message));
    }

    public function testTheBoosterTakesTheirBoostBack(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');
        $boost = $this->addBoost($message, $this->findUser('alice@example.com'), 'Nice');

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        self::assertResponseIsSuccessful();

        // The button posts the form of the boost with the method it asks for,
        // which is how a browser sends a delete.
        $this->client->submit($crawler->filter('#boost_'.$boost->getId().' form')->form());

        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->boostsOf($message));
    }

    public function testTheBoostOfSomeoneElseIsNotFound(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');
        $boost = $this->addBoost($message, $this->findUser('alice@example.com'), 'Nice');

        $this->addMember('Bob', 'bob@example.com');
        $this->signOut();
        $this->signIn('bob@example.com');

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('#boost_'.$boost->getId().' form')->form());

        // A member is told the boost is not there rather than that it belongs
        // to someone else, and the boost is left alone.
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->boostsOf($message));
    }

    public function testTakingABoostBackWithoutItsTokenIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');
        $boost = $this->addBoost($message, $this->findUser('alice@example.com'), 'Nice');

        $this->client->request('DELETE', '/messages/'.$message->getId().'/boosts/'.$boost->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->boostsOf($message));
    }

    public function testABoostOfAMessageInARoomTheUserIsNotInIsNotFound(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');
        $alice = $this->findUser('alice@example.com');

        $secret = $this->createClosedRoom('Secret', $alice);
        $message = $this->postMessage($secret, 'Only for Alice');

        // Reading Alice back after the request that posted the message keeps
        // the boost on an entity the current manager knows.
        $boost = $this->addBoost($message, $this->findUser('alice@example.com'), 'Nice');

        $this->signOut();
        $this->signIn('bob@example.com');

        // A non-member gets a not found rather than a forbidden, so the room
        // does not even reveal that it exists.
        $this->client->request('GET', '/messages/'.$message->getId().'/boosts');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/messages/'.$message->getId().'/boosts/new');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/messages/'.$message->getId().'/boosts', ['boost' => ['content' => 'Mine']]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/messages/'.$message->getId().'/boosts/'.$boost->getId());
        self::assertResponseStatusCodeSame(404);

        self::assertCount(1, $this->boostsOf($message));
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    /**
     * Creates a room only the given user takes part in, which is what makes a
     * non-member case testable.
     */
    private function createClosedRoom(string $name, User $creator): ClosedRoom
    {
        $entityManager = $this->entityManager();

        $room = new ClosedRoom();
        $room->setName($name);
        $room->setCreator($creator);

        $entityManager->persist($room);
        $entityManager->persist($room->addMember($creator));
        $entityManager->flush();

        return $room;
    }

    /**
     * Posts a message through the composer, then reads it back so the test can
     * address the stored row.
     */
    private function postMessage(Room $room, string $body): Message
    {
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => $body,
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        $message = $this->entityManager()->getRepository(Message::class)->findOneBy(
            ['room' => $room],
            ['id' => 'DESC'],
        );
        self::assertNotNull($message);

        return $message;
    }

    /**
     * Adds a boost the way the stored row is written, which is what the tests
     * that are about reading and removing one need.
     */
    private function addBoost(Message $message, User $booster, string $content): Boost
    {
        $entityManager = $this->entityManager();

        $boost = new Boost();
        $boost->setMessage($message);
        $boost->setBooster($booster);
        $boost->setContent($content);

        $entityManager->persist($boost);
        $entityManager->flush();

        return $boost;
    }

    /**
     * @return list<Boost>
     */
    private function boostsOf(Message $message): array
    {
        return $this->entityManager()->getRepository(Boost::class)->findBy(['message' => $message]);
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
}
