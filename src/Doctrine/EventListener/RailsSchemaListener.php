<?php

declare(strict_types=1);

namespace App\Doctrine\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Removes the constraints the Rails schema does not declare.
 *
 * The ORM adds a foreign key for every association, and an index for every
 * foreign key column. The Rails schema is sparser: boosts.booster_id,
 * memberships.room_id, memberships.user_id and rooms.creator_id are plain
 * columns there, and the rooms table carries no index at all. Without this the
 * schema tools would report those tables as out of sync and a migration diff
 * would rewrite them.
 *
 * Dropping the foreign key of rooms.creator_id is also what removes the index
 * DBAL derived from it. That index belongs to the table internals rather than
 * to the index list, so the editor never carries it, and a table rebuilt from
 * the editor only keeps the indexes the mapping declares.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class RailsSchemaListener
{
    /**
     * Columns the Rails schema declares without a foreign key.
     *
     * @var list<array{string, string}>
     */
    private const COLUMNS_WITHOUT_FOREIGN_KEY = [
        ['boosts', 'booster_id'],
        ['memberships', 'room_id'],
        ['memberships', 'user_id'],
        ['rooms', 'creator_id'],
    ];

    public function __invoke(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $editor = $schema->edit();
        $modified = false;

        foreach (self::COLUMNS_WITHOUT_FOREIGN_KEY as [$tableName, $columnName]) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $names = [];
            foreach ($schema->getTable($tableName)->getForeignKeys() as $name => $foreignKey) {
                if ([$columnName] === $foreignKey->getUnquotedLocalColumns()) {
                    $names[] = (string) $name;
                }
            }

            if ([] === $names) {
                continue;
            }

            $editor->modifyTableByUnquotedName($tableName, static function (TableEditor $table) use ($names): void {
                foreach ($names as $name) {
                    $table->dropForeignKeyConstraintByUnquotedName($name);
                }
            });
            $modified = true;
        }

        if ($modified) {
            $args->setSchema($editor->create());
        }
    }
}
