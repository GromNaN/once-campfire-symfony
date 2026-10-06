<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\GenerateSecretsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateSecretsCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'campfire-env-local-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testItWritesEverySecretToAMissingFile(): void
    {
        $tester = $this->executeCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $entries = $this->entries();

        self::assertSame(
            ['APP_SECRET', 'MERCURE_JWT_SECRET', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'],
            array_keys($entries),
        );

        foreach ($entries as $name => $value) {
            self::assertNotSame('', $value, $name.' is empty');
        }

        self::assertSame(32, \strlen($entries['APP_SECRET']));
        self::assertSame(64, \strlen($entries['MERCURE_JWT_SECRET']));
        self::assertStringEndsWith("\n", (string) file_get_contents($this->file));
    }

    public function testItKeepsTheValuesAlreadyPresent(): void
    {
        file_put_contents($this->file, "APP_SECRET=kept\nMERCURE_JWT_SECRET=\nVAPID_SUBJECT=mailto:campfire@localhost\n");

        $this->executeCommand();

        $entries = $this->entries();

        self::assertSame('kept', $entries['APP_SECRET']);
        self::assertNotSame('', $entries['MERCURE_JWT_SECRET']);
        self::assertNotSame('', $entries['VAPID_PUBLIC_KEY']);
        self::assertNotSame('', $entries['VAPID_PRIVATE_KEY']);
        self::assertSame('mailto:campfire@localhost', $entries['VAPID_SUBJECT']);
    }

    public function testItReplacesAnIncompleteVapidPair(): void
    {
        file_put_contents($this->file, "VAPID_PUBLIC_KEY=orphan\n");

        $this->executeCommand();

        $entries = $this->entries();

        self::assertNotSame('orphan', $entries['VAPID_PUBLIC_KEY']);
        self::assertNotSame('', $entries['VAPID_PRIVATE_KEY']);
    }

    public function testItLeavesAFileThatHasEverySecretAlone(): void
    {
        $this->executeCommand();
        $first = (string) file_get_contents($this->file);

        $tester = $this->executeCommand();

        self::assertSame($first, (string) file_get_contents($this->file));
        self::assertStringContainsString('already holds every secret', $tester->getDisplay());
    }

    private function executeCommand(): CommandTester
    {
        // The command is invokable rather than a Command subclass, so it is
        // handed to an application the way the container hands it: the
        // application reads its name from the AsCommand attribute and wraps it.
        $command = (new Application())->addCommand(new GenerateSecretsCommand($this->file));
        self::assertNotNull($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return array<string, string> The entries of the dotenv file, keyed by name.
     */
    private function entries(): array
    {
        $entries = [];

        foreach (file($this->file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2) + [1 => ''];
            $entries[$name] = $value;
        }

        return $entries;
    }
}
