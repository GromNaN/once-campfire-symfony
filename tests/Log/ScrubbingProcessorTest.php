<?php

declare(strict_types=1);

namespace App\Tests\Log;

use App\Log\ScrubbingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Covers the log scrubbing: a value named by a sensitive key is replaced, a
 * nested one is found, and everything else is left alone.
 */
final class ScrubbingProcessorTest extends TestCase
{
    public function testASensitiveValueIsReplaced(): void
    {
        $record = $this->process(['password' => 'hunter2', 'name' => 'Alice']);

        self::assertSame('[FILTERED]', $record->context['password']);
        self::assertSame('Alice', $record->context['name']);
    }

    public function testASensitiveValueIsFoundInANestedArray(): void
    {
        $record = $this->process([
            'user' => ['emailAddress' => 'alice@example.com', 'name' => 'Alice'],
        ]);

        self::assertSame('[FILTERED]', $record->context['user']['emailAddress']);
        self::assertSame('Alice', $record->context['user']['name']);
    }

    public function testTheBodyOfAMessageIsReplacedUnderItsMessage(): void
    {
        $record = $this->process([
            'message' => ['body' => 'a private sentence', 'id' => 7],
        ]);

        self::assertSame('[FILTERED]', $record->context['message']['body']);
        self::assertSame(7, $record->context['message']['id']);
    }

    public function testABodyOutsideAMessageIsLeftAlone(): void
    {
        $record = $this->process(['body' => 'a plain body']);

        self::assertSame('a plain body', $record->context['body']);
    }

    public function testTheExtraDataIsScrubbedToo(): void
    {
        $record = $this->process([], ['sessionToken' => 'secret-token']);

        self::assertSame('[FILTERED]', $record->extra['sessionToken']);
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private function process(array $context = [], array $extra = []): LogRecord
    {
        $record = new LogRecord(
            new \DateTimeImmutable('2026-10-06 12:00:00'),
            'app',
            Level::Info,
            'Something happened',
            $context,
            $extra,
        );

        return (new ScrubbingProcessor())($record);
    }
}
