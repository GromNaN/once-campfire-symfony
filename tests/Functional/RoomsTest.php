<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Account;
use App\Entity\ClosedRoom;
use App\Entity\DirectRoom;
use App\Entity\Enum\UserRole;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Message\MessageWriter;
use App\Service\AccountSetup;
use App\Service\RoomManager;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * Covers the room creation pages.
 *
 * The form is shown on a page of its own and saves somewhere else, so what
 * matters here is that the rendered form posts to the address that accepts it,
 * and that a room comes out of it.
 */
final class RoomsTest extends DatabaseTestCase
{
    public function testAnOpenRoomIsCreatedThroughTheForm(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        // An open room holds everyone, so there is nobody to pick.
        $form = $this->roomForm('/rooms/opens/new', 'Save', 'Engineering');

        $this->assertPostsTo($form, '/rooms/opens');
        $this->client->submit($form);

        $room = $this->lastRoom(OpenRoom::class);
        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertSame('Engineering', $room->getName());
        self::assertSame(['Alice', 'Bob'], $this->memberNames($room));
    }

    public function testAnOpenRoomShowsEveryoneWithoutAskingWhoToLetIn(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $crawler = $this->client->request('GET', '/rooms/opens/new');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('input[type="checkbox"][value="'.$bob->getId().'"]'));
        self::assertStringContainsString('Bob', $crawler->filter('.room-access')->text());
    }

    /**
     * The name field starts empty so the page shows the hint the reader types
     * over, rather than a value they would have to clear first.
     */
    public function testTheRoomNameStartsEmptyAndShowsItsPlaceholder(): void
    {
        $this->runFirstRun();

        foreach (['/rooms/opens/new', '/rooms/closeds/new'] as $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();

            $input = $crawler->filter('input[name="room[name]"]');
            self::assertCount(1, $input, $path.' shows the name field.');
            self::assertSame('', (string) $input->attr('value'), $path.' starts the name empty.');
            self::assertSame('Name the room', $input->attr('placeholder'), $path.' shows the hint.');
        }
    }

    public function testAClosedRoomIsCreatedThroughTheForm(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $form = $this->roomForm('/rooms/closeds/new', 'Save', 'Salaries', $bob);

        $this->assertPostsTo($form, '/rooms/closeds');
        $this->client->submit($form);

        $room = $this->lastRoom(ClosedRoom::class);
        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertSame('Salaries', $room->getName());
        self::assertSame(['Alice', 'Bob'], $this->memberNames($room));
    }

    public function testADirectRoomIsCreatedThroughTheForm(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $form = $this->roomForm('/rooms/directs/new', 'Start Ping', null, $bob);

        $this->assertPostsTo($form, '/rooms/directs');
        $this->client->submit($form);

        $room = $this->lastRoom(DirectRoom::class);
        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertSame(['Alice', 'Bob'], $this->memberNames($room));
    }

    public function testAnExistingConversationIsReusedInsteadOfCreatedAgain(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->client->submit($this->roomForm('/rooms/directs/new', 'Start Ping', null, $bob));
        $room = $this->lastRoom(DirectRoom::class);

        $this->client->submit($this->roomForm('/rooms/directs/new', 'Start Ping', null, $bob));

        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertCount(1, $this->entityManager()->getRepository(DirectRoom::class)->findAll());
    }

    public function testThePageIsShownAgainWhenTheRoomHasNoName(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        $this->client->submit($this->roomForm('/rooms/opens/new', 'Save', ''));

        self::assertResponseStatusCodeSame(422);

        // The page is shown again with the error, and the form still posts to
        // the address that creates the room rather than to the page itself.
        self::assertSelectorTextContains('body', 'Enter a name.');
        $this->assertPostsTo($this->client->getCrawler()->selectButton('Save')->form(), '/rooms/opens');
    }

    public function testTheSettingsOfARoomAreReachedFromTheRoom(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        self::assertResponseIsSuccessful();

        $link = $crawler->filter('a[data-room-id="'.$room->getId().'"]')->link();

        self::assertSame('/rooms/opens/'.$room->getId().'/edit', parse_url($link->getUri(), \PHP_URL_PATH));
    }

    public function testAnOpenRoomIsRenamedFromItsSettingsPage(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/opens/'.$room->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form(['room[name]' => 'Renamed']));

        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertSame('Renamed', $this->findRoom($room->getId())?->getName());
    }

    public function testAClosedRoomGrantsAndRevokesAccessFromItsSettingsPage(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $carol = $this->addMember('Carol', 'carol@example.com');

        $this->client->submit($this->roomForm('/rooms/closeds/new', 'Save', 'Salaries', $bob));
        $room = $this->lastRoom(ClosedRoom::class);

        $crawler = $this->client->request('GET', '/rooms/closeds/'.$room->getId().'/edit');
        self::assertResponseIsSuccessful();

        // Bob was in the room and leaves it, Carol joins it.
        $this->untick($crawler, $bob);
        $this->tick($crawler, $carol);

        $this->client->submit($crawler->selectButton('Save')->form());

        self::assertResponseRedirects('/rooms/'.$room->getId());

        $members = $this->memberNames($this->findRoom($room->getId()));
        self::assertSame(['Alice', 'Carol'], $members);
    }

    public function testAClosedRoomBecomesAnOpenOneWhenSavedFromTheOtherSettingsPage(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->client->submit($this->roomForm('/rooms/closeds/new', 'Save', 'Salaries', $bob));
        $room = $this->lastRoom(ClosedRoom::class);

        // The switch of the settings page leads to the settings page of the
        // other kind of room, and saving there turns the room into that kind.
        $crawler = $this->client->request('GET', '/rooms/closeds/'.$room->getId().'/edit');
        $switch = $crawler->filter('.room-access a')->link();

        self::assertSame('/rooms/opens/'.$room->getId().'/edit', parse_url($switch->getUri(), \PHP_URL_PATH));

        $crawler = $this->client->request('GET', '/rooms/opens/'.$room->getId().'/edit');
        $this->client->submit($crawler->selectButton('Save')->form(['room[name]' => 'Salaries']));

        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertInstanceOf(OpenRoom::class, $this->findRoom($room->getId()));
    }

    public function testAConversationShowsItsPeopleAndCanBeDeleted(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->client->submit($this->roomForm('/rooms/directs/new', 'Start Ping', null, $bob));
        $room = $this->lastRoom(DirectRoom::class);

        $crawler = $this->client->request('GET', '/rooms/directs/'.$room->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Bob', $crawler->filter('.directs--edit')->text());

        $this->client->submit($crawler->selectButton('Ping')->form());

        self::assertResponseRedirects('/');
        self::assertNull($this->findRoom($room->getId()));
    }

    public function testDeletingARoomTakesItsMessagesAndTheirBodiesWithIt(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $writer = static::getContainer()->get(MessageWriter::class);
        $writer->create($room, $this->findUser('alice@example.com'), 'A message with a body');

        $connection = $this->connection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messages'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM action_text_rich_texts'));

        $crawler = $this->client->request('GET', '/rooms/opens/'.$room->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action="/rooms/'.$room->getId().'"]')->form());

        self::assertResponseRedirects('/');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM messages'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM action_text_rich_texts'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM message_search_index'));
    }

    public function testAConversationIsNotReachableAsARoom(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->client->submit($this->roomForm('/rooms/directs/new', 'Start Ping', null, $bob));
        $room = $this->lastRoom(DirectRoom::class);

        // A conversation never turns into a room, so it is not one of the pages
        // that edit a room that the other kind of room shares.
        $this->client->request('GET', '/rooms/opens/'.$room->getId().'/edit');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/rooms/closeds/'.$room->getId().'/edit');
        self::assertResponseRedirects('/');

        // Saving is not where a conversation is reached either, and the address
        // of a room the reader is not in is answered as a room that does not
        // exist rather than as one they may not change.
        $this->client->request('PUT', '/rooms/opens/'.$room->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('PUT', '/rooms/closeds/'.$room->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAdministratorOutsideARoomCannotReachItsSettings(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');
        $bob = $this->addMember('Bob', 'bob@example.com');

        // A closed room Bob owns, which Alice is not a member of.
        $room = new ClosedRoom();
        $room->setName('Salaries');
        $room->setCreator($bob);
        static::getContainer()->get(RoomManager::class)->createFor($room, [$bob]);

        // Alice administers the account, and is still not a member of the room,
        // so the room stays out of reach of the page that would change it.
        $alice->setRole(UserRole::Administrator);
        $this->entityManager()->flush();

        $this->client->request('PUT', '/rooms/closeds/'.$room->getId());
        self::assertResponseStatusCodeSame(404);

        self::assertSame('Salaries', $this->findRoom($room->getId())?->getName());
    }

    public function testTheFirstRoomWelcomesTheReaderWithTheWayToInvitePeople(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#system_welcome'));
        self::assertStringContainsString('Welcome to Campfire', $crawler->filter('#system_welcome')->text());

        $account = $this->entityManager()->getRepository(Account::class)->findOneBy([]);
        self::assertNotNull($account);
        self::assertSame('/join/'.$account->getJoinCode(), parse_url((string) $crawler->filter('#invite_url')->attr('value'), \PHP_URL_PATH));
    }

    public function testARoomCreatedLaterDoesNotWelcomeTheReader(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        $this->client->submit($this->roomForm('/rooms/opens/new', 'Save', 'Engineering'));
        $room = $this->lastRoom(OpenRoom::class);

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#system_welcome'));
    }

    public function testTheWelcomeGoesAwayOnceTheFirstRoomHasMoreThanOnePageOfMessages(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $writer = static::getContainer()->get(MessageWriter::class);
        $creator = $this->findUser('alice@example.com');

        for ($i = 0; $i <= Message::PAGE_SIZE; $i++) {
            $writer->create($room, $creator, 'Message '.$i);
        }

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#system_welcome'));
    }

    public function testPostingAMessageMovesTheRoomUpTheSidebar(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $before = $room->getUpdatedAt();

        $this->postMessage($room, 'First post');

        $after = $this->findRoom($room->getId())?->getUpdatedAt();
        self::assertNotNull($after);
        self::assertGreaterThan((float) $before->format('U.u'), (float) $after->format('U.u'));
    }

    /**
     * Posts a message through the composer the way a browser does.
     */
    private function postMessage(Room $room, string $body): void
    {
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => $body,
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
    }

    /**
     * Fills a room creation page the way the browser does and hands back the
     * form, so that the test can look at it before it is submitted.
     */
    private function roomForm(string $path, string $button, ?string $name, User ...$members): Form
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $this->pick($crawler, ...$members);

        $form = $crawler->selectButton($button)->form();

        if (null !== $name) {
            $form['room[name]'] = $name;
        }

        return $form;
    }

    /**
     * Picks the people the page offers, whichever way it offers them: a closed
     * room shows a switch per person, a conversation a list to pick names from.
     */
    private function pick(Crawler $crawler, User ...$members): void
    {
        if ($members === []) {
            return;
        }

        $select = $crawler->filter('select[multiple]');

        if ($select->count() > 0) {
            foreach ($members as $member) {
                $option = $select->filter('option[value="'.$member->getId().'"]');
                self::assertCount(1, $option, 'The person to pick has to be offered on the page.');
                $option->getNode(0)->setAttribute('selected', 'selected');
            }

            return;
        }

        $this->tick($crawler, ...$members);
    }

    private function tick(Crawler $crawler, User ...$members): void
    {
        foreach ($members as $member) {
            $checkbox = $this->checkbox($crawler, $member);
            $checkbox->getNode(0)->setAttribute('checked', 'checked');
        }
    }

    private function untick(Crawler $crawler, User ...$members): void
    {
        foreach ($members as $member) {
            $checkbox = $this->checkbox($crawler, $member);
            $checkbox->getNode(0)->removeAttribute('checked');
        }
    }

    private function checkbox(Crawler $crawler, User $member): Crawler
    {
        $checkbox = $crawler->filter('input[type="checkbox"][value="'.$member->getId().'"]');
        self::assertCount(1, $checkbox, 'The person to pick has to be offered on the page.');

        return $checkbox;
    }

    private function findRoom(int $id): ?Room
    {
        return $this->entityManager()->getRepository(Room::class)->find($id);
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    private function assertPostsTo(Form $form, string $path): void
    {
        self::assertSame($path, parse_url($form->getUri(), \PHP_URL_PATH));
    }

    /**
     * @param class-string<Room> $class
     */
    private function lastRoom(string $class): Room
    {
        $room = $this->entityManager()->getRepository($class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($room);
        self::assertInstanceOf($class, $room);

        return $room;
    }

    /**
     * @return list<string>
     */
    private function memberNames(Room $room): array
    {
        $names = [];

        foreach ($room->getMemberships() as $membership) {
            $names[] = (string) $membership->getUser()?->getName();
        }

        sort($names);

        return $names;
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
}
