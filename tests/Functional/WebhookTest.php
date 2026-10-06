<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActionText\SignedId;
use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\Bot\WebhookClient;
use App\Bot\WebhookDelivery;
use App\Entity\DirectRoom;
use App\Entity\Enum\MembershipInvolvement;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\PushSubscription;
use App\Entity\Room;
use App\Entity\User;
use App\Entity\Webhook;
use App\Form\Data\RegistrationData;
use App\Message\MessageBody;
use App\Message\MessageWriter;
use App\Push\WebPushSender;
use App\Service\AccountSetup;
use App\Tests\Support\RecordingPushSender;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers what a bot is told when a message is posted, and what it answers.
 *
 * The endpoint is never really called: the client the delivery uses is
 * replaced with one that answers from the test, so a test says what a bot
 * answers and checks what the room is given back.
 */
final class WebhookTest extends DatabaseTestCase
{
    private const ENDPOINT = 'http://bot.example/campfire';

    /**
     * The endpoint of a browser that asked to be notified, which is not the
     * address the bot answers on.
     */
    private const PUSH_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * Every request the delivery made, in order.
     *
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    private array $requests = [];

    private RecordingPushSender $pushes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pushes = new RecordingPushSender();
    }

    public function testADirectRoomTellsTheBotAboutEveryMessage(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp($this->text(''));

        $message = $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::ENDPOINT, $this->requests[0]['url']);
        self::assertSame($message->getId(), $this->payload()['message']['id']);
    }

    public function testThePayloadCarriesTheMessageAndTheRoom(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bot = $this->createBot('Recorder', self::ENDPOINT, $room);
        $this->mockHttp($this->text(''));
        $alice = $this->findUser('alice@example.com');

        $message = $this->post($room, $alice, '<p>Hello '.$this->mention($bot).'</p>');

        $payload = $this->payload();

        self::assertSame(['user', 'room', 'message'], array_keys($payload));
        self::assertSame(['id' => $alice->getId(), 'name' => 'Alice'], $payload['user']);
        self::assertSame([
            'id' => $room->getId(),
            'name' => 'All Talk',
            'path' => '/rooms/'.$room->getId().'/'.$bot->getBotKey().'/messages',
        ], $payload['room']);
        self::assertSame($message->getId(), $payload['message']['id']);
        self::assertSame('/rooms/'.$room->getId().'/@'.$message->getId(), $payload['message']['path']);

        // The mention is still there for a reader, and taken out of the text
        // the bot reads, so that a bot answering "@bot deploy" is not told to
        // deploy again. The stored HTML is normalized, so it is compared with
        // the entities a parser resolves rather than byte for byte.
        $html = html_entity_decode($payload['message']['body']['html'], \ENT_QUOTES | \ENT_HTML5);
        self::assertStringContainsString($this->mention($bot), $html);
        self::assertSame('Hello', $payload['message']['body']['plain']);
    }

    public function testTheAuthorIsNeverToldAboutItsOwnMessage(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp($this->text(''));

        $this->post($room, $bot, 'I said this one myself');

        self::assertSame([], $this->requests);
    }

    public function testASharedRoomOnlyTellsTheBotsTheMessageMentions(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $mentioned = $this->createBot('Mentioned', self::ENDPOINT, $room);
        $other = $this->createBot('Other', 'http://other.example/campfire', $room);
        $this->mockHttp($this->text(''));
        $alice = $this->findUser('alice@example.com');

        $this->post($room, $alice, '<p>Hello '.$this->mention($mentioned).'</p>');

        self::assertCount(1, $this->requests);
        self::assertSame(self::ENDPOINT, $this->requests[0]['url']);

        // A message that mentions nobody reaches no bot at all, which is what
        // makes a shared room quiet by default.
        $this->post($room, $alice, 'Nothing for anyone');

        self::assertCount(1, $this->requests);
        self::assertNotNull($other->getId());
    }

    public function testADeactivatedBotIsNotTold(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Retired', self::ENDPOINT, status: UserStatus::Deactivated);
        $room = $this->conversation($bot);
        $this->mockHttp($this->text(''));

        $this->post($room, $this->findUser('alice@example.com'), 'Anyone there?');

        self::assertSame([], $this->requests);
    }

    public function testABotWithoutAnEndpointIsNotTold(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Quiet');
        $room = $this->conversation($bot);
        $this->mockHttp($this->text(''));

        $this->post($room, $this->findUser('alice@example.com'), 'Anyone there?');

        self::assertSame([], $this->requests);
    }

    public function testTheTextOfTheAnswerIsPostedInTheRoom(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp($this->text('Sure thing'));

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        $replies = $this->repliesIn($room, $bot);
        self::assertCount(1, $replies);
        self::assertSame('Sure thing', $this->body()->plainText($replies[0]));
    }

    public function testAnAnswerNotifiesTheReadersWhoAreAway(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bot = $this->createBot('Recorder', self::ENDPOINT, $room);
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->involve($room, $bob, MembershipInvolvement::Everything);
        $this->subscribe($bob);
        $this->mockHttp($this->text('Sure thing'));

        $this->post($room, $this->findUser('alice@example.com'), '<p>Hello '.$this->mention($bot).'</p>');

        // Two notifications reach the reader who is away: one for the message
        // that named the bot, and one for the answer the bot posted, which is a
        // message like any other.
        self::assertSame(2, $this->pushes->count());
        self::assertSame([self::PUSH_ENDPOINT, self::PUSH_ENDPOINT], $this->pushes->endpoints());
    }

    public function testAnAnswerOfHtmlIsPostedInTheRoom(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(new MockResponse('<p>Deployed</p>', [
            'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
        ]));

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        $replies = $this->repliesIn($room, $bot);
        self::assertCount(1, $replies);
        self::assertSame('Deployed', $this->body()->plainText($replies[0]));
    }

    public function testAnAnswerThatIsAFileIsPostedAsAnAttachment(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(new MockResponse('%PDF-1.4 the report', [
            'response_headers' => ['content-type' => 'application/pdf'],
        ]));

        $this->post($room, $this->findUser('alice@example.com'), 'Send me the report');

        $replies = $this->repliesIn($room, $bot);
        self::assertCount(1, $replies);

        // The name is built from the media type the bot answered with, which is
        // what a member sees offered for download.
        $blob = static::getContainer()->get(Attachments::class)->blobFor($replies[0], Attachments::ATTACHMENT);
        self::assertNotNull($blob);
        self::assertSame('attachment.pdf', $blob->getFilename());
        self::assertSame('application/pdf', $blob->getContentType());
        self::assertSame('%PDF-1.4 the report', static::getContainer()->get(BlobStorage::class)->read($blob));
    }

    public function testAnEndpointThatDoesNotAnswerSaysSoInTheRoom(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(static function (): MockResponse {
            throw new TimeoutException('Timeout was reached');
        });

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        $replies = $this->repliesIn($room, $bot);
        self::assertCount(1, $replies);
        self::assertSame('Failed to respond within 7 seconds', $this->body()->plainText($replies[0]));
    }

    public function testAnEndpointThatCannotBeReachedIsNotTriedAgain(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(static function (): MockResponse {
            // A name that does not resolve, a refused connection or a broken
            // handshake all raise the same transport error.
            throw new TransportException('Could not resolve host');
        });

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        // The delivery is a single attempt: a transport error is not retried,
        // and it says nothing in the room.
        self::assertCount(0, $this->repliesIn($room, $bot));
    }

    public function testAnAnswerLargerThanTheLimitIsCutShort(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(new MockResponse(str_repeat('a', WebhookDelivery::MAX_BODY_BYTES + 1000), [
            'response_headers' => ['content-type' => 'application/pdf'],
        ]));

        $this->post($room, $this->findUser('alice@example.com'), 'Send me the report');

        $replies = $this->repliesIn($room, $bot);
        self::assertCount(1, $replies);

        $blob = static::getContainer()->get(Attachments::class)->blobFor($replies[0], Attachments::ATTACHMENT);
        self::assertNotNull($blob);

        // An endpoint that answers with more than the limit cannot take the
        // worker down with it, so only the limit is read.
        self::assertSame(WebhookDelivery::MAX_BODY_BYTES, \strlen(static::getContainer()->get(BlobStorage::class)->read($blob)));
    }

    public function testAnAnswerThatIsNotSuccessfulIsIgnored(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(new MockResponse('Gone', [
            'response_headers' => ['content-type' => 'text/plain'],
            'http_code' => 404,
        ]));

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        self::assertCount(0, $this->repliesIn($room, $bot));
    }

    public function testAnAnswerOfAnUnknownTypeIsIgnored(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(new MockResponse('???', [
            'response_headers' => ['content-type' => 'application/x-campfire-test'],
        ]));

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        self::assertCount(0, $this->repliesIn($room, $bot));
    }

    public function testAnAnswerWithoutAContentTypeIsIgnored(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Recorder', self::ENDPOINT);
        $room = $this->conversation($bot);
        $this->mockHttp(new MockResponse('Something'));

        $this->post($room, $this->findUser('alice@example.com'), 'Deploy please');

        self::assertCount(0, $this->repliesIn($room, $bot));
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
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

    private function subscribe(User $user): PushSubscription
    {
        $entityManager = $this->entityManager();

        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setEndpoint(self::PUSH_ENDPOINT);
        $subscription->setP256dhKey('BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM');
        $subscription->setAuthKey('tBHItJI5svbpez7KI4CCXg');
        $subscription->setUserAgent('Test browser');

        $entityManager->persist($subscription);
        $entityManager->flush();

        return $subscription;
    }

    private function involve(Room $room, User $user, MembershipInvolvement $involvement): void
    {
        $membership = $this->entityManager()->getRepository(Membership::class)->findOneBy([
            'room' => $room,
            'user' => $user,
        ]);
        self::assertNotNull($membership);

        $membership->setInvolvement($involvement);
        $this->entityManager()->flush();
    }

    /**
     * A direct room between Alice and the bot, which is where a bot is told
     * about every message without being mentioned.
     */
    private function conversation(User $bot): DirectRoom
    {
        $entityManager = $this->entityManager();
        $alice = $this->findUser('alice@example.com');

        $room = new DirectRoom();
        $room->setCreator($alice);

        $entityManager->persist($room);
        $entityManager->persist($room->addMember($alice));
        $entityManager->persist($room->addMember($bot));
        $entityManager->flush();

        return $room;
    }

    private function createBot(
        string $name,
        ?string $endpoint = null,
        ?Room $room = null,
        UserStatus $status = UserStatus::Active,
    ): User {
        $entityManager = $this->entityManager();

        $bot = new User();
        $bot->setName($name);
        $bot->setRole(UserRole::Bot);
        $bot->setStatus($status);
        $bot->setBotToken(User::generateBotToken());

        $entityManager->persist($bot);

        if (null !== $room) {
            $entityManager->persist($room->addMember($bot));
        }

        $entityManager->flush();

        if (null !== $endpoint) {
            $webhook = new Webhook();
            $webhook->setUrl($endpoint);

            $entityManager->persist($bot->addWebhook($webhook));
            $entityManager->flush();
        }

        return $bot;
    }

    /**
     * Posts a message the way the application does, which is what makes the
     * delivery run.
     */
    private function post(Room $room, User $creator, string $body): Message
    {
        $container = static::getContainer();

        // The recording sender goes in just before the message is written,
        // because a request made earlier in the test reboots the kernel and
        // takes the container with it. A second post in the same test finds the
        // sender already built, and a built service cannot be replaced.
        if (!$container->initialized(WebPushSender::class)) {
            $container->set(WebPushSender::class, $this->pushes);
        }

        return $container->get(MessageWriter::class)->create($room, $creator, $body);
    }

    /**
     * @return list<Message>
     */
    private function repliesIn(Room $room, User $bot): array
    {
        return $this->entityManager()->getRepository(Message::class)->findBy(
            ['room' => $room, 'creator' => $bot],
            ['id' => 'ASC'],
        );
    }

    private function body(): MessageBody
    {
        return static::getContainer()->get(MessageBody::class);
    }

    /**
     * Replaces the client a webhook is called with, and remembers what was sent.
     *
     * @param MockResponse|callable(): MockResponse $answer
     */
    private function mockHttp(MockResponse|callable $answer): void
    {
        static::getContainer()->set(WebhookClient::class, new WebhookClient(new MockHttpClient(
            function (string $method, string $url, array $options) use ($answer): MockResponse {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return $answer instanceof MockResponse ? clone $answer : $answer();
            },
        )));
    }

    /**
     * The body a bot was sent, read out of the last request.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        self::assertNotSame([], $this->requests);

        return json_decode((string) $this->requests[0]['options']['body'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private function text(string $text): MockResponse
    {
        return new MockResponse($text, ['response_headers' => ['content-type' => 'text/plain']]);
    }

    /**
     * The attachment element a body keeps for a mention.
     */
    private function mention(User $user): string
    {
        $sgid = static::getContainer()->get(SignedId::class)->encode('User', (int) $user->getId(), SignedId::PURPOSE_ATTACHABLE);

        return \sprintf('<action-text-attachment sgid="%s" content-type="application/vnd.campfire.mention"></action-text-attachment>', $sgid);
    }
}
