<?php

declare(strict_types=1);

namespace App\Tests\Room;

use App\Entity\DirectRoom;
use App\Entity\OpenRoom;
use App\Entity\User;
use App\Room\RoomDisplayName;
use PHPUnit\Framework\TestCase;

/**
 * A room is named by its name, a direct room by the people in it.
 *
 * The identifiers are written onto the unsaved people because the membership
 * comparison reads them, and a test does not need a database to check a name.
 */
final class RoomDisplayNameTest extends TestCase
{
    private RoomDisplayName $names;

    protected function setUp(): void
    {
        $this->names = new RoomDisplayName();
    }

    public function testAnOpenRoomIsNamedByItsName(): void
    {
        $room = new OpenRoom();
        $room->setName('Salaries');

        self::assertSame('Salaries', $this->names->displayName($room));
    }

    public function testADirectRoomIsNamedByItsOtherPeople(): void
    {
        $alice = self::withId((new User())->setName('Alice'), 1);
        $bob = self::withId((new User())->setName('Bob'), 2);

        $room = new DirectRoom();
        $room->addMember($alice);
        $room->addMember($bob);

        self::assertSame('Bob', $this->names->displayName($room, $alice));
        self::assertSame('Alice', $this->names->displayName($room, $bob));
    }

    public function testADirectRoomJoinsThreeNamesTheWayRailsDoes(): void
    {
        $alice = self::withId((new User())->setName('Alice'), 1);
        $bob = self::withId((new User())->setName('Bob'), 2);
        $carol = self::withId((new User())->setName('Carol'), 3);

        $room = new DirectRoom();
        $room->addMember($alice);
        $room->addMember($bob);
        $room->addMember($carol);

        self::assertSame('Bob and Carol', $this->names->displayName($room, $alice));
        self::assertSame('Alice, Bob, and Carol', $this->names->displayName($room));
    }

    public function testADirectRoomWithNobodyElseIsNamedAfterTheReader(): void
    {
        $alice = (new User())->setName('Alice');

        self::assertSame('Alice', $this->names->displayName(new DirectRoom(), $alice));
    }

    private static function withId(User $user, int $id): User
    {
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
