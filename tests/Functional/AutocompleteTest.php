<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\AutocompletableUsersController;
use App\Entity\ClosedRoom;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Service\AccountSetup;

/**
 * Covers the user list the editor and the pickers read while someone types.
 *
 * The list is served twice from the same action: the prompt items the editor
 * inserts, and the plain records a client of its own reads.
 */
final class AutocompleteTest extends DatabaseTestCase
{
    public function testThePromptItemsAreServedToTheEditor(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        $this->client->request('GET', '/autocompletable/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('lexxy-prompt-item[search="Alice"]');
        self::assertSelectorExists('lexxy-prompt-item[search="Bob"]');
        self::assertSelectorExists('lexxy-prompt-item[search="Alice"] template[type="editor"]');
    }

    public function testTheRecordsAreServedAsJson(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/autocompletable/users.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $records = $this->decode();

        self::assertCount(1, $records);
        self::assertSame('Alice', $records[0]['name']);
        self::assertSame((int) $this->findUser('alice@example.com')->getId(), $records[0]['value']);
        self::assertNotSame('', $records[0]['avatar_url']);
        self::assertNotSame('', $records[0]['sgid']);
    }

    public function testTheListIsNarrowedByFilter(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        // The editor's mentions prompt filters with "filter".
        $this->client->request('GET', '/autocompletable/users?filter=bo');

        self::assertSelectorExists('lexxy-prompt-item[search="Bob"]');
        self::assertSelectorNotExists('lexxy-prompt-item[search="Alice"]');
    }

    public function testTheListIsNarrowedByQuery(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        // The autocomplete inputs filter with "query".
        $this->client->request('GET', '/autocompletable/users.json?query=bo');

        $records = $this->decode();

        self::assertCount(1, $records);
        self::assertSame('Bob', $records[0]['name']);
    }

    public function testTheListIsNarrowedToTheMembersOfARoom(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $this->addMember('Carol', 'carol@example.com');

        // Alice and Bob take part in the room, Carol does not.
        $room = $this->createClosedRoom('Secret', $this->findUser('alice@example.com'), $bob);

        $this->client->request('GET', '/autocompletable/users.json?room_id='.$room->getId());

        $records = $this->decode();

        self::assertSame(['Alice', 'Bob'], array_column($records, 'name'));
    }

    public function testARoomTheMemberIsNotInIsNotFound(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $secret = $this->createClosedRoom('Secret', $bob);

        $this->client->request('GET', '/autocompletable/users?room_id='.$secret->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInactiveMemberIsLeftOut(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $entityManager = $this->entityManager();
        $bob = $entityManager->find(User::class, $bob->getId());
        self::assertNotNull($bob);
        $bob->setStatus(UserStatus::Deactivated);
        $entityManager->flush();

        $this->client->request('GET', '/autocompletable/users');

        self::assertSelectorExists('lexxy-prompt-item[search="Alice"]');
        self::assertSelectorNotExists('lexxy-prompt-item[search="Bob"]');
    }

    public function testTheListIsServedAPageAtATime(): void
    {
        $this->runFirstRun();

        for ($index = 1; $index <= AutocompletableUsersController::PER_PAGE; ++$index) {
            $this->addMember('Member '.$index, 'member'.$index.'@example.com');
        }

        $this->client->request('GET', '/autocompletable/users.json');
        self::assertCount(AutocompletableUsersController::PER_PAGE, $this->decode());

        // The members are ordered by name, so the second page holds the members
        // whose name comes after the first twenty.
        $this->client->request('GET', '/autocompletable/users.json?page=2');

        self::assertCount(1, $this->decode());
    }

    public function testASignedOutVisitorIsAskedToSignIn(): void
    {
        $this->runFirstRun();
        $this->signOut();

        $this->client->request('GET', '/autocompletable/users');

        self::assertResponseRedirects('/session/new');
    }

    /**
     * @return list<array{name: string, value: int, avatar_url: string, sgid: string}>
     */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function createClosedRoom(string $name, User $creator, User ...$members): ClosedRoom
    {
        $entityManager = $this->entityManager();

        $room = new ClosedRoom();
        $room->setName($name);
        $room->setCreator($creator);

        $entityManager->persist($room);

        foreach ([$creator, ...$members] as $member) {
            $entityManager->persist($room->addMember($member));
        }

        $entityManager->flush();

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
}
