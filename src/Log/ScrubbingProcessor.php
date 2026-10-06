<?php

declare(strict_types=1);

namespace App\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Keeps the values that must not be read back out of the logs.
 *
 * The original application filters the parameters it writes, so a password, an
 * email address or a session token never lands in a log file. The same keys are
 * filtered here, in the context and the extra data of every record, on every
 * channel, because a logger is shared by the whole application.
 */
#[AsMonologProcessor]
final class ScrubbingProcessor
{
    private const FILTERED = '[FILTERED]';

    /**
     * The parts of a key that mark its value as sensitive, taken from the
     * filter_parameter_logging initializer of the original application.
     */
    private const SENSITIVE = [
        'passw',
        'secret',
        'token',
        '_key',
        'crypt',
        'salt',
        'certificate',
        'otp',
        'ssn',
        'cvv',
        'cvc',
        'endpoint',
        'email',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * Walks the values and replaces what a sensitive key names. A nested array
     * is walked with its own key as the parent, so that a key only meaningful
     * under another one, such as the body of a message, can be told apart.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function scrub(array $values, ?string $parent = null): array
    {
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = $this->scrub($value, \is_string($key) ? $key : $parent);

                continue;
            }

            if ($this->isSensitive((string) $key, $parent)) {
                $values[$key] = self::FILTERED;
            }
        }

        return $values;
    }

    private function isSensitive(string $key, ?string $parent): bool
    {
        $key = strtolower($key);

        // A message body is private, and is filtered under the message it
        // belongs to, the way "message.body" is in the original application.
        if ('body' === $key && 'message' === $parent) {
            return true;
        }

        foreach (self::SENSITIVE as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }
}
