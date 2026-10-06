<?php

declare(strict_types=1);

namespace App\Doctrine\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Removes the default length the ORM puts on string columns.
 *
 * SQLite stores a string as an unbounded varchar, which is what Rails writes
 * and what the migration declares. The ORM, on the other hand, describes every
 * string column as varchar(255) unless the mapping says otherwise, so without
 * this the schema tools would see those columns as changed and a migration
 * diff would rewrite the whole database.
 *
 * Only the columns that carry the ORM default are touched, so a column the
 * mapping gives a length to, such as boosts.content, keeps it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class UnboundedStringSchemaListener
{
    public function __invoke(GenerateSchemaEventArgs $args): void
    {
        $defaultLength = $args->getEntityManager()->getConfiguration()->getDefaultStringTypeSchemaLength();

        foreach ($args->getSchema()->getTables() as $table) {
            foreach ($table->getColumns() as $column) {
                if ($column->getType() instanceof StringType && $defaultLength === $column->getLength()) {
                    $column->setLength(null);
                }
            }
        }
    }
}
