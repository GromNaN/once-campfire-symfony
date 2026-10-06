<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * Stores timestamps exactly like Rails does: "Y-m-d H:i:s.u" in UTC.
 *
 * Rails writes microsecond precision without a timezone marker and always in UTC,
 * so the database stays readable by the original application in both directions.
 */
final class RailsDateTimeType extends DateTimeImmutableType
{
    public const NAME = 'rails_datetime';

    private const FORMAT = 'Y-m-d H:i:s.u';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $dateTime = $value instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($value)
            : new \DateTimeImmutable((string) $value);

        return $dateTime
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $dateTime = \DateTimeImmutable::createFromFormat(self::FORMAT, (string) $value, new \DateTimeZone('UTC'));

        if (false === $dateTime) {
            // Older rows, or hand-written fixtures, may lack the microsecond part.
            $dateTime = new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
    }
}
