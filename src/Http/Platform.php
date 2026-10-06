<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What a browser runs on.
 *
 * The page that explains how to allow notifications has to say which browser
 * and which system it is talking about, because the steps differ. The names
 * are the ones a reader sees in their own menus, which is why they are written
 * the way they are rather than as identifiers.
 */
final class Platform
{
    public const SAFARI = 'Safari';
    public const CHROME = 'Chrome';
    public const FIREFOX = 'Firefox';
    public const EDGE = 'Edge';
    public const OPERA = 'Opera';
    public const UNKNOWN = 'browser';

    /**
     * The system to name when the user agent does not say which one it is.
     * Every sentence this appears in reads well with it: "your system
     * settings".
     */
    private const UNKNOWN_SYSTEM = 'system';

    private function __construct(
        private readonly string $userAgent,
        public readonly string $browser,
        public readonly string $operatingSystem,
    ) {
    }

    public static function fromUserAgent(?string $userAgent): self
    {
        $userAgent ??= '';

        return new self($userAgent, self::browserName($userAgent), self::operatingSystemName($userAgent));
    }

    public function isIos(): bool
    {
        return $this->matches('#iPhone|iPad#');
    }

    public function isAndroid(): bool
    {
        return $this->matches('#Android#');
    }

    public function isMac(): bool
    {
        return $this->matches('#Macintosh#');
    }

    public function isMobile(): bool
    {
        return $this->isIos() || $this->isAndroid();
    }

    public function isDesktop(): bool
    {
        return !$this->isMobile();
    }

    public function isWindows(): bool
    {
        return 'Windows' === $this->operatingSystem;
    }

    public function isChrome(): bool
    {
        return self::CHROME === $this->browser;
    }

    public function isFirefox(): bool
    {
        return self::FIREFOX === $this->browser;
    }

    public function isSafari(): bool
    {
        return self::SAFARI === $this->browser;
    }

    public function isEdge(): bool
    {
        return self::EDGE === $this->browser;
    }

    /**
     * A link shared through Apple Messages is fetched by a robot that claims
     * to be Facebook and Twitter at once. It is not a browser at all, so the
     * page it lands on does not tell it that its browser is too old.
     */
    public function isAppleMessages(): bool
    {
        return $this->matches('#facebookexternalhit#i') && $this->matches('#Twitterbot#');
    }

    private function matches(string $pattern): bool
    {
        return 1 === preg_match($pattern, $this->userAgent);
    }

    /**
     * Edge and Opera also say Chrome, and Chrome also says Safari, so the most
     * specific name has to be read first.
     */
    private static function browserName(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('#(?:Edge|Edg[ei]?)/#', $userAgent) => self::EDGE,
            (bool) preg_match('#OPR/#', $userAgent) => self::OPERA,
            (bool) preg_match('#(?:Chrome|CriOS)/#', $userAgent) => self::CHROME,
            (bool) preg_match('#(?:Firefox|FxiOS)/#', $userAgent) => self::FIREFOX,
            (bool) preg_match('#Safari/#', $userAgent) => self::SAFARI,
            default => self::UNKNOWN,
        };
    }

    private static function operatingSystemName(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('#Android#', $userAgent) => 'Android',
            (bool) preg_match('#iPad#', $userAgent) => 'iPad',
            (bool) preg_match('#iPhone#', $userAgent) => 'iPhone',
            (bool) preg_match('#Macintosh#', $userAgent) => 'macOS',
            (bool) preg_match('#Windows#', $userAgent) => 'Windows',
            (bool) preg_match('#CrOS#', $userAgent) => 'ChromeOS',
            (bool) preg_match('#Linux#', $userAgent) => 'Linux',
            default => self::UNKNOWN_SYSTEM,
        };
    }
}
