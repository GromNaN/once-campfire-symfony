<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Reads the browser name and version out of a user agent string.
 *
 * Only the browsers the application knows about are recognized. An unknown
 * browser is never blocked, which is what the original application does.
 */
final class Browser
{
    public const SAFARI = 'safari';
    public const CHROME = 'chrome';
    public const FIREFOX = 'firefox';
    public const OPERA = 'opera';
    public const INTERNET_EXPLORER = 'ie';
    public const UNKNOWN = 'unknown';

    /**
     * The oldest version of each browser the application runs on, which is
     * what the original application allows. Internet Explorer is refused
     * outright, which is what the false stands for.
     *
     * @var array<string, float|false>
     */
    public const MINIMUM_VERSIONS = [
        self::SAFARI => 17.2,
        self::CHROME => 120,
        self::FIREFOX => 121,
        self::OPERA => 104,
        self::INTERNET_EXPLORER => false,
    ];

    /**
     * The browsers the upgrade page lists, with the version to reach.
     *
     * @return array<string, float>
     */
    public static function supportedVersions(): array
    {
        return array_filter(self::MINIMUM_VERSIONS, static fn (float|false $version): bool => false !== $version);
    }

    /**
     * Order matters: Edge and Opera also advertise Chrome, and Chrome also
     * advertises Safari.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        self::INTERNET_EXPLORER => '#(?:MSIE |Trident/)#',
        self::OPERA => '#OPR/#',
        self::CHROME => '#(?:Chrome|CriOS)/#',
        self::FIREFOX => '#Firefox/#',
        self::SAFARI => '#Safari/#',
    ];

    private function __construct(
        public readonly string $name,
        public readonly ?float $version,
    ) {
    }

    public static function fromUserAgent(?string $userAgent): self
    {
        if (null === $userAgent || '' === $userAgent) {
            return new self(self::UNKNOWN, null);
        }

        foreach (self::PATTERNS as $name => $pattern) {
            if (1 === preg_match($pattern, $userAgent)) {
                return new self($name, self::extractVersion($userAgent, $name));
            }
        }

        return new self(self::UNKNOWN, null);
    }

    private static function extractVersion(string $userAgent, string $name): ?float
    {
        $pattern = match ($name) {
            self::INTERNET_EXPLORER => '#(?:MSIE |rv:)(\d+(?:\.\d+)?)#',
            self::OPERA => '#OPR/(\d+(?:\.\d+)?)#',
            self::CHROME => '#(?:Chrome|CriOS)/(\d+(?:\.\d+)?)#',
            self::FIREFOX => '#Firefox/(\d+(?:\.\d+)?)#',
            default => '#Version/(\d+(?:\.\d+)?)#',
        };

        if (1 !== preg_match($pattern, $userAgent, $matches)) {
            return null;
        }

        return (float) $matches[1];
    }
}
