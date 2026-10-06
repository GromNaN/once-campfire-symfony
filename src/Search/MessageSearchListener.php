<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;

/**
 * Takes a deleted message out of the full text index.
 *
 * The listener follows the message rather than being called from the
 * controllers, because a message is also deleted when its room or its author
 * goes away, and the index has to follow every one of those paths.
 *
 * The work happens before the deletion rather than after it, because Doctrine
 * clears the identifier of an entity before it announces that the entity is
 * gone, and the identifier is what the index is keyed by. Both writes happen in
 * the transaction of the flush, so the index row and the message row go away
 * together or not at all.
 */
#[AsEntityListener(event: 'preRemove', method: 'preRemove', entity: Message::class)]
final class MessageSearchListener
{
    public function __construct(private readonly MessageIndex $index)
    {
    }

    public function preRemove(Message $message, PreRemoveEventArgs $args): void
    {
        $this->index->remove($message);
    }
}
