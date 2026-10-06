<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ClosedRoom;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Service\AccountSetup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Covers the message routes the way a browser walks them: read, edit, update
 * and delete a message, and the answer a user gets for a message they are not
 * allowed to see.
 */
final class MessagesTest extends DatabaseTestCase
{
    public function testAMemberReadsAMessageOfTheirRoom(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#message_'.$message->getKey(), 'First post');
    }

    public function testAMessageOfNothingButEmojiIsMarkedAsSuch(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $emoji = $this->postMessage($room, '🎉');
        $words = $this->postMessage($room, 'A party');

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message_'.$emoji->getKey().'.message--emoji');
        self::assertSelectorNotExists('#message_'.$words->getKey().'.message--emoji');
    }

    public function testPostingAnswersWithAStreamThatAppendsTheMessage(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => 'Hello everyone',
            'message[clientMessageId]' => 'b7c1c6f0-0000-4000-8000-000000000002',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        // The answer is read as a stream rather than as a page, which is what
        // makes the browser append the message instead of replacing the page.
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html');

        $stream = $this->client->getCrawler()->filter('turbo-stream');
        self::assertCount(1, $stream);
        self::assertSame('append', $stream->attr('action'));
        self::assertSame('messages_'.$room->getId(), $stream->attr('targets'));
        self::assertStringContainsString(
            'id="message_b7c1c6f0-0000-4000-8000-000000000002"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testAMessageOfAnotherRoomIsNotFound(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $other = $this->createClosedRoom('Secret', $this->findUser('alice@example.com'));

        // The message exists, but not in the room the URL points at.
        $this->client->request('GET', '/rooms/'.$other->getId().'/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMessageOfARoomTheUserIsNotInIsNotFound(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');
        $alice = $this->findUser('alice@example.com');

        $secret = $this->createClosedRoom('Secret', $alice);
        $message = $this->postMessage($secret, 'Only for Alice');

        $this->signOut();
        $this->signIn('bob@example.com');

        // A non-member gets a not found rather than a forbidden, so the room
        // does not even reveal that it exists.
        $this->client->request('GET', '/rooms/'.$secret->getId().'/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCreatorEditsTheirMessage(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save changes')->form([
            'message[body]' => 'Edited post',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());
        self::assertSelectorTextContains('#message_'.$message->getKey(), 'Edited post');
    }

    public function testAMemberCannotEditTheMessageOfSomeoneElse(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $this->addMember('Bob', 'bob@example.com');
        $this->signOut();
        $this->signIn('bob@example.com');

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheCreatorDeletesTheirMessage(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $this->client->request('DELETE', '/rooms/'.$room->getId().'/messages/'.$message->getId(), [], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(Message::class)->find($message->getId()));
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
