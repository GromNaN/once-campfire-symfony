<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Message;
use App\Entity\User;
use App\Message\MessageBody;
use App\Repository\MessageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The full text index of messages.
 *
 * SQLite keeps it in an FTS5 table of its own, whose rowid is the message id,
 * exactly like the original application. The table is not mapped as an entity:
 * it holds no data of its own, it is a search view of the message bodies, so it
 * is written and read through SQL.
 *
 * A message is indexed once it is complete, because its body and its file are
 * written after the row itself. Removal is not called by hand: the entity
 * listener of the message takes the row out of the index whenever a message is
 * deleted, whichever way it is deleted.
 */
final class MessageIndex
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessageRepository $messages,
        private readonly MessageBody $body,
    ) {
    }

    /**
     * Writes the text of a message into the index, replacing what was there.
     */
    public function index(Message $message): void
    {
        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO message_search_index (rowid, body) VALUES (?, ?)',
            [(int) $message->getId(), $this->body->plainText($message)],
            [ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function remove(Message $message): void
    {
        $this->connection->executeStatement(
            'DELETE FROM message_search_index WHERE rowid = ?',
            [(int) $message->getId()],
            [ParameterType::INTEGER],
        );
    }

    /**
     * The messages of the rooms the user belongs to that match the query, at
     * most the last hundred of them, oldest first.
     *
     * The index is walked newest first, which is the order of the row ids, so
     * the page is the most recent matches rather than an arbitrary hundred.
     *
     * @return list<Message>
     */
    public function search(User $user, string $query, int $size = 100): array
    {
        $terms = self::matchTerms($query);

        if ('' === $terms) {
            return [];
        }

        $ids = $this->connection->fetchFirstColumn(
            'SELECT rowid FROM message_search_index WHERE body MATCH :terms ORDER BY rowid DESC LIMIT :size',
            ['terms' => $terms, 'size' => $size],
            ['terms' => ParameterType::STRING, 'size' => ParameterType::INTEGER],
        );

        if ([] === $ids) {
            return [];
        }

        return $this->messages->findReachableByIds($user, array_map(intval(...), $ids));
    }

    /**
     * Turns what a person typed into a full text query.
     *
     * Every word is quoted, so that a word like AND or OR is searched for
     * rather than read as an operator. Quoting is what the original application
     * does, and it is what makes a query of a single stop word work instead of
     * failing.
     */
    public static function matchTerms(string $query): string
    {
        $words = preg_split('/\s+/', trim($query), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            static fn (string $word): string => '"'.str_replace('"', '""', $word).'"',
            $words,
        ));
    }
}
