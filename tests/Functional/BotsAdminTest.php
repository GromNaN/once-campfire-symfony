<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\RoomRepository;
use App\Repository\WebhookRepository;
use Symfony\Component\DomCrawler\Form;

/**
 * Covers the chat bots an administrator manages from the account page: adding
 * one, editing it, handing it a new key, and retiring it.
 *
 * The key is the only credential a bot has, so most of what is checked here is
 * that a key which was retired no longer opens anything.
 */
final class BotsAdminTest extends DatabaseTestCase
{
    public function testAnAdministratorCreatesABotThatJoinsTheOpenRooms(): void
    {
        $this->runFirstRun();

        $this->client->submit($this->newBotForm(['bot[name]' => 'Deploy']));

        self::assertResponseRedirects('/account/bots');

        $bot = $this->findBot('Deploy');
        self::assertSame(UserRole::Bot, $bot->getRole());
        self::assertSame(UserStatus::Active, $bot->getStatus());
        self::assertNotSame('', $bot->getBotKey());

        // A new bot joins the open rooms, the way any new member does.
        $memberships = static::getContainer()->get(MembershipRepository::class)->findAllForUserOrdered($bot);
        self::assertCount(1, $memberships);
        self::assertSame('All Talk', $memberships[0]->getRoom()?->getName());

        // The page hands out the commands that post into those rooms.
        $crawler = $this->client->request('GET', '/account/bots');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value*="'.$bot->getBotKey().'"]');
        self::assertSelectorExists('input[value*="/rooms/'.$memberships[0]->getRoom()?->getId().'/'.$bot->getBotKey().'/messages"]');
    }

    public function testAnAdministratorCreatesABotWithAWebhook(): void
    {
        $this->runFirstRun();

        $this->client->submit($this->newBotForm([
            'bot[name]' => 'Deploy',
            'bot[webhookUrl]' => 'https://example.com/hooks/deploy',
        ]));

        self::assertResponseRedirects('/account/bots');

        $webhook = $this->webhooks()->findOneBy(['user' => $this->findBot('Deploy')]);
        self::assertNotNull($webhook);
        self::assertSame('https://example.com/hooks/deploy', $webhook->getUrl());
    }

    public function testCreatingABotWithoutANameIsRefused(): void
    {
        $this->runFirstRun();

        $this->client->submit($this->newBotForm(['bot[name]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['role' => UserRole::Bot]));
    }

    public function testAnAdministratorRenamesABotAndClearsItsWebhook(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Deploy', 'https://example.com/hooks/deploy');

        $crawler = $this->client->request('GET', '/account/bots/'.$bot->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form([
            'bot[name]' => 'Release',
            'bot[webhookUrl]' => '',
        ]));

        self::assertResponseRedirects('/account/bots');

        $bot = $this->findBot('Release');
        self::assertNull($this->webhooks()->findOneBy(['user' => $bot]));
    }

    public function testGeneratingANewKeyRetiresTheOldOne(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Deploy');
        $room = static::getContainer()->get(RoomRepository::class)->findOpenRooms()[0];
        $oldKey = $bot->getBotKey();

        $crawler = $this->client->request('GET', '/account/bots/'.$bot->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Generate a new key')->form());

        self::assertResponseRedirects('/account/bots');

        $newKey = $this->findBot('Deploy')->getBotKey();
        self::assertNotSame($oldKey, $newKey);

        // The key that was already copied stops opening the room.
        $this->client->request('POST', '/rooms/'.$room->getId().'/'.$oldKey.'/messages', [], [], [], 'Hello!');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/rooms/'.$room->getId().'/'.$newKey.'/messages', [], [], [], 'Hello!');
        self::assertResponseStatusCodeSame(201);
    }

    public function testRemovingABotRetiresItsKey(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Deploy', 'https://example.com/hooks/deploy');
        $room = static::getContainer()->get(RoomRepository::class)->findOpenRooms()[0];
        $key = $bot->getBotKey();

        $crawler = $this->client->request('GET', '/account/bots/'.$bot->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Delete this chat bot')->form());

        self::assertResponseRedirects('/account/bots');

        $bot = $this->entityManager()->getRepository(User::class)->find($bot->getId());
        self::assertSame(UserStatus::Deactivated, $bot?->getStatus());
        self::assertNull($this->webhooks()->findOneBy(['user' => $bot]));

        // It is gone from the page, and its key is refused.
        $this->client->request('GET', '/account/bots');
        self::assertStringNotContainsString('Deploy', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/rooms/'.$room->getId().'/'.$key.'/messages', [], [], [], 'Hello!');
        self::assertResponseStatusCodeSame(401);
    }

    public function testABotKeyPostsAMessageIntoTheRoom(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Deploy');
        $room = static::getContainer()->get(RoomRepository::class)->findOpenRooms()[0];

        $this->client->request('POST', '/rooms/'.$room->getId().'/'.$bot->getBotKey().'/messages', [], [], [], 'Deployment finished.');

        self::assertResponseStatusCodeSame(201);

        $messages = static::getContainer()->get(MessageRepository::class)->findLastPage($room);
        self::assertCount(1, $messages);
        self::assertInstanceOf(Message::class, $messages[0]);
        self::assertSame($bot->getId(), $messages[0]->getCreator()?->getId());
    }

    public function testABotKeyWithAWrongTokenIsRefused(): void
    {
        $this->runFirstRun();
        $bot = $this->createBot('Deploy');
        $room = static::getContainer()->get(RoomRepository::class)->findOpenRooms()[0];

        $this->client->request('POST', '/rooms/'.$room->getId().'/'.$bot->getId().'-notthetoken/messages', [], [], [], 'Hello!');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * The form on the page that adds a bot, with the given fields filled in.
     *
     * @param array<string, string> $fields
     */
    private function newBotForm(array $fields = []): Form
    {
        $crawler = $this->client->request('GET', '/account/bots/new');
        self::assertResponseIsSuccessful();

        return $crawler->filter('form')->form($fields);
    }

    private function createBot(string $name, ?string $webhookUrl = null): User
    {
        $fields = ['bot[name]' => $name];

        if (null !== $webhookUrl) {
            $fields['bot[webhookUrl]'] = $webhookUrl;
        }

        $this->client->submit($this->newBotForm($fields));
        self::assertResponseRedirects('/account/bots');

        return $this->findBot($name);
    }

    private function findBot(string $name): User
    {
        $bot = $this->entityManager()->getRepository(User::class)->findOneBy(['name' => $name]);
        self::assertNotNull($bot);

        return $bot;
    }

    private function webhooks(): WebhookRepository
    {
        return static::getContainer()->get(WebhookRepository::class);
    }
}
