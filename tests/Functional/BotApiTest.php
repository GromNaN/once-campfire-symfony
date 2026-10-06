<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ClosedRoom;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use App\Entity\Webhook;
use App\Message\MessageWriter;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Covers the JSON endpoints a bot calls with its key in the URL.
 *
 * A bot is not signed in: it carries "<id>-<token>" in the path, and that key
 * is the whole of its credential. The tests walk the endpoints the way a bot
 * written against the original application walks them, including the headers
 * the list carries and the refusals a bot gets for what is not its own.
 */
final class BotApiTest extends DatabaseTestCase
{
    /**
     * Directories holding the files built by the test.
     *
     * @var list<string>
     */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        $this->directories = [];

        parent::tearDown();
    }

    public function testABotPostsTheTextOfTheRequestBodyAsAMessage(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');

        $this->client->request('POST', $this->url($room, $bot), [], [], ['CONTENT_TYPE' => 'text/plain'], 'Deployed to production');

        self::assertResponseStatusCodeSame(201);

        $message = $this->lastMessage($room);
        self::assertSame(
            '/rooms/'.$room->getId().'/messages/'.$message->getId(),
            parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH),
        );
    }

    public function testAnEmptyBodyIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');

        $this->client->request('POST', $this->url($room, $bot), [], [], ['CONTENT_TYPE' => 'text/plain'], '   ');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->messagesIn($room));
    }

    public function testABotPostsAFileAsAMessage(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');

        $this->client->request('POST', $this->url($room, $bot), [], [
            'attachment' => new UploadedFile($this->notesFile(), 'notes.txt', 'text/plain', null, true),
        ]);

        self::assertResponseStatusCodeSame(201);

        // A message that carries a file is read back by its name, which is the
        // text the original application gives for it.
        $message = $this->lastMessage($room);

        $this->client->request('GET', $this->url($room, $bot, '/'.$message->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('notes.txt', $this->decode()['body']['plain_text']);
    }

    public function testTheListDescribesEveryMessageTheWayABotReadsIt(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $this->postMessage($room, $bot, 'Hello from the bot');

        $this->client->request('GET', $this->url($room, $bot));

        self::assertResponseIsSuccessful();

        $messages = $this->decode();

        self::assertCount(1, $messages);
        self::assertSame(['id', 'created_at', 'body', 'creator', 'room', 'url'], array_keys($messages[0]));
        self::assertSame(['plain_text', 'html'], array_keys($messages[0]['body']));
        self::assertSame('Hello from the bot', $messages[0]['body']['plain_text']);
        self::assertSame('<div class="lexxy-content">Hello from the bot</div>', $messages[0]['body']['html']);
        self::assertSame(['id', 'name', 'role', 'avatar_url'], array_keys($messages[0]['creator']));
        self::assertSame('Recorder', $messages[0]['creator']['name']);
        self::assertSame('bot', $messages[0]['creator']['role']);
        self::assertSame(['id' => $room->getId()], $messages[0]['room']);
        self::assertSame(
            'http://localhost/rooms/'.$room->getId().'/messages/'.$messages[0]['id'],
            $messages[0]['url'],
        );

        // The time is the one Rails writes, and the picture is an address a bot
        // outside the application can read.
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $messages[0]['created_at'],
        );
        self::assertStringStartsWith('http://localhost/users/', $messages[0]['creator']['avatar_url']);
    }

    public function testTheListTellsHowManyMessagesTheRoomHolds(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $this->fill($room, $bot, 3);

        $this->client->request('GET', $this->url($room, $bot));

        self::assertResponseIsSuccessful();
        self::assertSame('3', $this->client->getResponse()->headers->get('X-Total-Count'));
        self::assertCount(3, $this->decode());
    }

    public function testTheListPointsToThePageBeforeWhenThereIsOne(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $messages = $this->fill($room, $bot, 41);

        $this->client->request('GET', $this->url($room, $bot));

        $page = $this->decode();
        self::assertCount(40, $page);
        self::assertSame($messages[1]->getId(), $page[0]['id']);

        $link = (string) $this->client->getResponse()->headers->get('Link');
        self::assertSame(
            'http://localhost'.$this->url($room, $bot).'?before='.$messages[1]->getId(),
            $this->nextLink($link),
        );

        // Following the link gives the message that was left out, and there is
        // nothing further to walk to.
        $this->client->request('GET', $this->nextLink($link));

        self::assertResponseIsSuccessful();
        self::assertSame([$messages[0]->getId()], array_column($this->decode(), 'id'));
        self::assertNull($this->client->getResponse()->headers->get('Link'));
    }

    public function testTheListPointsToThePageAfterWhenThereIsOne(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $messages = $this->fill($room, $bot, 42);

        $this->client->request('GET', $this->url($room, $bot).'?after='.$messages[0]->getId());

        $page = $this->decode();
        self::assertCount(40, $page);
        self::assertSame($messages[1]->getId(), $page[0]['id']);
        self::assertSame($messages[40]->getId(), $page[39]['id']);

        $link = (string) $this->client->getResponse()->headers->get('Link');
        self::assertSame(
            'http://localhost'.$this->url($room, $bot).'?after='.$messages[40]->getId(),
            $this->nextLink($link),
        );

        $this->client->request('GET', $this->nextLink($link));

        self::assertResponseIsSuccessful();
        self::assertSame([$messages[41]->getId()], array_column($this->decode(), 'id'));
        self::assertNull($this->client->getResponse()->headers->get('Link'));
    }

    public function testASingleMessageIsRead(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $message = $this->postMessage($room, $bot, 'Only this one');

        $this->client->request('GET', $this->url($room, $bot, '/'.$message->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($message->getId(), $this->decode()['id']);
    }

    public function testABotEditsItsOwnMessage(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $message = $this->postMessage($room, $bot, 'First version');

        $this->client->request('PUT', $this->url($room, $bot, '/'.$message->getId()), [], [], ['CONTENT_TYPE' => 'text/plain'], 'Second version');

        self::assertResponseIsSuccessful();
        self::assertSame('Second version', $this->decode()['body']['plain_text']);

        // The change is stored, not only echoed back.
        $this->client->request('GET', $this->url($room, $bot, '/'.$message->getId()));

        self::assertSame('Second version', $this->decode()['body']['plain_text']);
    }

    public function testABotCannotEditTheMessageOfSomeoneElse(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $author = $this->createBot($room, 'Author');
        $other = $this->createBot($room, 'Other');
        $message = $this->postMessage($room, $author, 'First version');

        $this->client->request('PUT', $this->url($room, $other, '/'.$message->getId()), [], [], ['CONTENT_TYPE' => 'text/plain'], 'Second version');

        self::assertResponseStatusCodeSame(403);
    }

    public function testABotDeletesItsOwnMessage(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $message = $this->postMessage($room, $bot, 'Gone soon');

        $this->client->request('DELETE', $this->url($room, $bot, '/'.$message->getId()));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->messagesIn($room));
    }

    public function testABotCannotDeleteTheMessageOfSomeoneElse(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $author = $this->createBot($room, 'Author');
        $other = $this->createBot($room, 'Other');
        $message = $this->postMessage($room, $author, 'Not for you');

        $this->client->request('DELETE', $this->url($room, $other, '/'.$message->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->messagesIn($room));
    }

    public function testARoomTheBotIsNotInIsNotFound(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');

        // A second room the bot was never added to.
        $entityManager = $this->entityManager();
        $other = new ClosedRoom();
        $other->setName('Elsewhere');
        $other->setCreator($this->findUser('alice@example.com'));
        $entityManager->persist($other);
        $entityManager->flush();

        $this->client->request('GET', '/rooms/'.$other->getId().'/'.$bot->getBotKey().'/messages');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAKeyThatMatchesNoBotIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $this->createBot($room, 'Recorder');

        $this->client->request('GET', '/rooms/'.$room->getId().'/999-abcdefghijkl/messages');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheKeyOfADeactivatedBotIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Retired', status: UserStatus::Deactivated);

        $this->client->request('GET', $this->url($room, $bot));

        self::assertResponseStatusCodeSame(401);
    }

    public function testABotAddsABoost(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $message = $this->postMessage($room, $bot, 'Worth a boost');

        $this->client->request('POST', $this->url($room, $bot, '/'.$message->getId().'/boosts'), [], [], ['CONTENT_TYPE' => 'text/plain'], 'nice');

        self::assertResponseStatusCodeSame(201);

        $boost = $this->decode();
        self::assertSame(['id', 'content', 'created_at', 'booster', 'message'], array_keys($boost));
        self::assertSame('nice', $boost['content']);
        self::assertSame('Recorder', $boost['booster']['name']);
        self::assertSame($message->getId(), $boost['message']['id']);
    }

    public function testAnEmptyBoostIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $message = $this->postMessage($room, $bot, 'Worth a boost');

        $this->client->request('POST', $this->url($room, $bot, '/'.$message->getId().'/boosts'), [], [], ['CONTENT_TYPE' => 'text/plain'], '  ');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM boosts'));
    }

    public function testABotTakesBackItsOwnBoost(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $bot = $this->createBot($room, 'Recorder');
        $message = $this->postMessage($room, $bot, 'Worth a boost');

        $this->client->request('POST', $this->url($room, $bot, '/'.$message->getId().'/boosts'), [], [], ['CONTENT_TYPE' => 'text/plain'], 'nice');
        $boostId = $this->decode()['id'];

        $this->client->request('DELETE', $this->url($room, $bot, '/'.$message->getId().'/boosts/'.$boostId));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM boosts'));
    }

    public function testABotCannotTakeBackTheBoostOfAnotherBot(): void
    {
        $this->runFirstRun();
        $room = $this->room();
        $author = $this->createBot($room, 'Author');
        $other = $this->createBot($room, 'Other');
        $message = $this->postMessage($room, $author, 'Worth a boost');

        $this->client->request('POST', $this->url($room, $author, '/'.$message->getId().'/boosts'), [], [], ['CONTENT_TYPE' => 'text/plain'], 'nice');
        $boostId = $this->decode()['id'];

        $this->client->request('DELETE', $this->url($room, $other, '/'.$message->getId().'/boosts/'.$boostId));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM boosts'));
    }

    /**
     * A closed room Alice owns, which is the room the bots are put in.
     */
    private function room(): ClosedRoom
    {
        $entityManager = $this->entityManager();
        $alice = $this->findUser('alice@example.com');

        $room = new ClosedRoom();
        $room->setName('Bot talk');
        $room->setCreator($alice);

        $entityManager->persist($room);
        $entityManager->persist($room->addMember($alice));
        $entityManager->flush();

        return $room;
    }

    private function createBot(
        Room $room,
        string $name,
        ?string $webhookUrl = null,
        UserStatus $status = UserStatus::Active,
    ): User {
        $entityManager = $this->entityManager();

        $bot = new User();
        $bot->setName($name);
        $bot->setRole(UserRole::Bot);
        $bot->setStatus($status);
        $bot->setBotToken(User::generateBotToken());

        $entityManager->persist($bot);
        $entityManager->persist($room->addMember($bot));
        $entityManager->flush();

        if (null !== $webhookUrl) {
            $webhook = new Webhook();
            $webhook->setUrl($webhookUrl);
            $webhook->setUser($bot);

            $entityManager->persist($webhook);
            $entityManager->flush();
        }

        return $bot;
    }

    /**
     * The address a bot calls, with the key it authenticates with in the path.
     */
    private function url(Room $room, User $bot, string $suffix = ''): string
    {
        return '/rooms/'.$room->getId().'/'.$bot->getBotKey().'/messages'.$suffix;
    }

    /**
     * Posts a message the way the application does, which is what a test uses
     * when it needs a message of a known author.
     */
    private function postMessage(Room $room, User $creator, string $body): Message
    {
        return static::getContainer()->get(MessageWriter::class)->create($room, $creator, $body);
    }

    /**
     * Fills a room with messages whose times are one minute apart, so that a
     * page is walked in a settled order.
     *
     * @return list<Message>
     */
    private function fill(Room $room, User $creator, int $count): array
    {
        $base = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $messages = [];

        for ($index = 1; $index <= $count; ++$index) {
            $message = $this->postMessage($room, $creator, 'Message '.$index);
            $message->setCreatedAt($base->modify('+'.$index.' minutes'));
            $messages[] = $message;
        }

        $this->entityManager()->flush();

        return $messages;
    }

    private function lastMessage(Room $room): Message
    {
        $message = $this->entityManager()->getRepository(Message::class)->findOneBy(['room' => $room], ['id' => 'DESC']);
        self::assertNotNull($message);

        return $message;
    }

    private function messagesIn(Room $room): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messages WHERE room_id = '.$room->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * The address of the next page, read out of the link header.
     */
    private function nextLink(string $header): string
    {
        self::assertSame(1, preg_match('#<([^>]+)>; rel="next"#', $header, $matches), 'The link header names no next page.');

        return $matches[1];
    }

    /**
     * A file to upload, named the way a bot names one.
     */
    private function notesFile(): string
    {
        $directory = sys_get_temp_dir().'/campfire-bot-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->directories[] = $directory;

        $path = $directory.'/notes.txt';
        self::assertNotFalse(file_put_contents($path, 'The build passed.'));

        return $path;
    }
}
