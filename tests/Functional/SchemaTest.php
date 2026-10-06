<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Checks that the schema the migration builds is the schema the mapping
 * describes.
 *
 * The two are kept in step by hand, since the migration follows the Rails
 * schema while the mapping follows the entities, and the schema listeners only
 * shape the mapping side. This is what catches a column or a constraint that
 * was changed on one side only.
 */
final class SchemaTest extends DatabaseTestCase
{
    public function testTheSchemaBuiltFromTheMigrationMatchesTheMapping(): void
    {
        $application = new Application(static::getContainer()->get('kernel'));
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput([
            'command' => 'doctrine:schema:validate',
            '--no-interaction' => true,
        ]), $output);

        self::assertSame(0, $exitCode, $output->fetch());
    }
}
