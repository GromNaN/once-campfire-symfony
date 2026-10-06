<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ClosedRoom;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\Search;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Repository\SearchRepository;
use App\Service\AccountSetup;

/**
 * Covers search the way a member uses it: what the index holds after a message
 * is written, changed or deleted, and what the search pages answer.
 */
final class SearchTest extends DatabaseTestCase
{
    public function testAPostedMessageIsFound(): void
    {
        $this->runFirstRun();
        $message = $this->postMessage($this->openRoom(), 'The paragliding club meets on Thursday');

        $this->client->request('GET', '/searches?q=paragliding');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message_'.$message->getKey());
    }

    public function testAWordIsFoundByItsStem(): void
    {
        $this->runFirstRun();
        $message = $this->postMessage($this->openRoom(), 'Running in the park this morning');

        // The index stems words, so "run" finds "running".
        $this->client->request('GET', '/searches?q=run');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message_'.$message->getKey());
    }

    public function testAWordThatLooksLikeAnOperatorIsSearchedFor(): void
    {
        $this->runFirstRun();
        $message = $this->postMessage($this->openRoom(), 'To be or not to be');

        // Every word is quoted, so "or" is a word to find rather than a syntax
        // error handed to the index.
        $this->client->request('GET', '/searches?q=or');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message_'.$message->getKey());
    }

    public function testAMessageOfARoomTheMemberIsNotInIsNotFound(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $secret = $this->createClosedRoom('Secret', $bob);

        $this->signOut();
        $this->signIn('bob@example.com');
        $this->postMessage($secret, 'The paragliding club');

        $this->signOut();
        $this->signIn('alice@example.com');
        $this->client->request('GET', '/searches?q=paragliding');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#search-results .message');
    }

    public function testAnEditedMessageIsFoundByItsNewTextOnly(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'alpha');

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId().'/edit');
        $this->client->submit($crawler->selectButton('Save changes')->form([
            'message[body]' => 'bravo',
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/searches?q=alpha');
        self::assertSelectorNotExists('#search-results .message');

        $this->client->request('GET', '/searches?q=bravo');
        self::assertSelectorExists('#message_'.$message->getKey());
    }

    public function testADeletedMessageIsNoLongerFound(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'paragliding');

        $this->client->request('DELETE', '/rooms/'.$room->getId().'/messages/'.$message->getId(), [], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/searches?q=paragliding');

        self::assertSelectorNotExists('#search-results .message');
        self::assertSame(0, $this->indexedRows());
    }

    public function testDeletingARoomTakesItsMessagesOutOfTheIndex(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $this->postMessage($room, 'paragliding');

        self::assertSame(1, $this->indexedRows());

        // A room is deleted with its messages, and the index has to follow even
        // when nothing goes through the message routes.
        $entityManager = $this->entityManager();
        $entityManager->remove($entityManager->getRepository(OpenRoom::class)->find($room->getId()));
        $entityManager->flush();

        self::assertSame(0, $this->indexedRows());
    }

    public function testASearchWithNoWordFindsNothing(): void
    {
        $this->runFirstRun();
        $this->postMessage($this->openRoom(), 'The paragliding club');

        $this->client->request('GET', '/searches?q=%21%21%21');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#search-results .message');
    }

    public function testSubmittingASearchRecordsIt(): void
    {
        $this->runFirstRun();
        $this->postMessage($this->openRoom(), 'The paragliding club');

        $crawler = $this->client->request('GET', '/searches');
        $this->client->submit($crawler->selectButton('Search')->form(['q' => 'paragliding']));

        self::assertResponseRedirects('/searches?q=paragliding');

        $this->client->followRedirect();
        self::assertSelectorExists('#search-results .message');
        self::assertSelectorTextContains('.searches__recents', 'paragliding');
        self::assertSame(1, $this->searchCount());
    }

    public function testRunningTheSameSearchAgainKeepsOneEntry(): void
    {
        $this->runFirstRun();

        $this->recordSearch('paragliding');
        $this->recordSearch('paragliding');

        self::assertSame(1, $this->searchCount());
    }

    public function testOnlyTheTenMostRecentSearchesAreKept(): void
    {
        $this->runFirstRun();
        $searches = static::getContainer()->get(SearchRepository::class);

        for ($index = 1; $index <= 11; ++$index) {
            $searches->record($this->findUser('alice@example.com'), 'query '.$index);
            // The list is ordered by the time of the search, so the searches
            // have to happen at different times to have an order at all.
            usleep(1000);
        }

        $kept = $searches->findRecentFor($this->findUser('alice@example.com'));

        self::assertCount(Search::MAX_RECENT, $kept);
        self::assertSame(10, $this->searchCount());
        self::assertNotContains('query 1', array_map(static fn (Search $search) => $search->getQuery(), $kept));
    }

    public function testClearingTheRecentSearchesRemovesThem(): void
    {
        $this->runFirstRun();
        $this->recordSearch('paragliding');

        $crawler = $this->client->request('GET', '/searches');
        $this->client->submit($crawler->selectButton('Clear recent searches')->form());

        self::assertResponseRedirects('/searches');
        self::assertSame(0, $this->searchCount());
    }

    public function testClearingTheRecentSearchesWithoutATokenIsRefused(): void
    {
        $this->runFirstRun();
        $this->recordSearch('paragliding');

        $this->client->request('DELETE', '/searches/clear');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->searchCount());
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

    private function recordSearch(string $query): void
    {
        static::getContainer()->get(SearchRepository::class)->record($this->findUser('alice@example.com'), $query);
    }

    /**
     * The number of rows the full text index holds.
     */
    private function indexedRows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM message_search_index');
    }

    private function searchCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM searches');
    }
}
