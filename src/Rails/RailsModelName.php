<?php

declare(strict_types=1);

namespace App\Rails;

use App\Entity\Account;
use App\Entity\ActiveStorageBlob;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;

/**
 * Name the Rails application stores for a record.
 *
 * ActiveStorage and ActionText keep a model name in their polymorphic columns
 * instead of a foreign key, so the same rows can be read by both applications.
 * The name is the Rails one, which is not always the PHP class name: the three
 * room types are Rooms::Open, Rooms::Closed and Rooms::Direct there, and they
 * all live in a single table called Room.
 */
final class RailsModelName
{
    public const ROOM = 'Room';
    public const USER = 'User';
    public const ACCOUNT = 'Account';
    public const MESSAGE = 'Message';

    /**
     * Uploaded files are named with their namespace, which is how Rails writes
     * a namespaced model name. The signed identifiers the rich text body
     * carries use it as well, so a body written by the original application
     * points at the same row here.
     */
    public const BLOB = 'ActiveStorage::Blob';

    /**
     * @param object|class-string $record
     */
    public static function of(object|string $record): string
    {
        $class = \is_object($record) ? $record::class : $record;

        return match (true) {
            is_a($class, Room::class, true) => self::ROOM,
            is_a($class, User::class, true) => self::USER,
            is_a($class, Account::class, true) => self::ACCOUNT,
            is_a($class, Message::class, true) => self::MESSAGE,
            is_a($class, ActiveStorageBlob::class, true) => self::BLOB,
            default => self::namespacedName($class),
        };
    }

    /**
     * A class without an explicit name keeps its own name, with the separators
     * the original application uses.
     */
    private static function namespacedName(string $class): string
    {
        return str_replace('\\', '::', $class);
    }
}
