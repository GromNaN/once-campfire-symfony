<?php

declare(strict_types=1);

namespace App\Opengraph;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * What a page says about itself, in the shape a link preview needs.
 *
 * A preview is only worth showing when the page names itself and describes
 * itself, so a page without a title or without a description has none. The
 * address of the preview is the one the page declares as its own when that
 * address can be reached, and the address that was linked otherwise.
 */
final class Metadata
{
    /**
     * Twitter and X do not serve OpenGraph tags, so their pages are read
     * through the mirror that does. Nothing is asked of the mirror beyond what
     * the page itself says.
     */
    private const TWITTER_HOSTS = ['twitter.com', 'www.twitter.com', 'x.com', 'www.x.com'];
    private const MIRROR_HOST = 'fxtwitter.com';

    /**
     * The image types a preview may show. A file of any other type is not
     * drawn, which leaves the preview without a picture rather than broken.
     */
    private const ALLOWED_IMAGE_CONTENT_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * A link to a file or to a media is a link to download something, not a
     * page to describe, so it is left as a plain link.
     */
    private const FILE_URL_PATTERN = '~\bhttps?://\S+\.(?:zip|tar|tar\.gz|tar\.bz2|tar\.xz|gz|bz2|rar|7z|dmg|exe|msi|pkg|deb|iso|jpg|jpeg|png|gif|bmp|mp4|mov|avi|mkv|wmv|flv|heic|heif|mp3|wav|ogg|aac|wma|webm|ogv|mpg|mpeg)\b~i';

    private function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $description,
        public readonly ?string $image,
    ) {
    }

    /**
     * Reads a page and returns what a preview shows of it, or null when the
     * page cannot be read or has nothing to show.
     */
    public static function fromUrl(Fetcher $fetcher, string $url): ?self
    {
        if (!self::isPublicUrl($url) || self::isFileUrl($url)) {
            return null;
        }

        $html = $fetcher->document(self::mirrorUrl($url));

        if (null === $html) {
            return null;
        }

        $attributes = Document::fromHtml($html)->opengraphAttributes();

        $title = self::text($attributes['title'] ?? '');
        $description = self::text($attributes['description'] ?? '');
        $image = self::image($fetcher, $attributes['image'] ?? '');

        if (null === $image && '' !== ($attributes['image'] ?? '')) {
            // The page showed a picture that cannot be reached. The original
            // application refuses the whole preview in that case.
            return null;
        }

        if ('' === $title || '' === $description) {
            return null;
        }

        return new self(
            $title,
            self::publicUrl($attributes['url'] ?? '') ?? $url,
            $description,
            $image,
        );
    }

    /**
     * @return array{title: string, url: string, image: string|null, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'image' => $this->image,
            'description' => $this->description,
        ];
    }

    /**
     * The picture to show, or null when the page has none or serves a file that
     * is not a picture.
     */
    private static function image(Fetcher $fetcher, string $image): ?string
    {
        if ('' === $image) {
            return null;
        }

        if (!self::isPublicUrl($image)) {
            return null;
        }

        $contentType = $fetcher->contentTypeOf($image);

        return \in_array($contentType, self::ALLOWED_IMAGE_CONTENT_TYPES, true) ? $image : null;
    }

    /**
     * A tag holds HTML, and a preview shows text.
     */
    private static function text(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5));
    }

    /**
     * The address when it can be reached, and null when it cannot.
     */
    private static function publicUrl(string $url): ?string
    {
        return self::isPublicUrl($url) ? $url : null;
    }

    /**
     * Whether an address is one the application is willing to read.
     *
     * Only HTTP addresses are read, and only when their host is a name or an
     * address on the public internet: a link to the machine the application
     * runs on, or to the network behind it, is not something a preview is
     * allowed to reach.
     */
    private static function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';

        return '' !== $host && self::isPublicHost($host);
    }

    private static function isFileUrl(string $url): bool
    {
        return 1 === preg_match(self::FILE_URL_PATTERN, $url);
    }

    private static function isPublicHost(string $host): bool
    {
        $addresses = self::addressesOf($host);

        if ([] === $addresses) {
            return false;
        }

        foreach ($addresses as $address) {
            if (IpUtils::isPrivateIp($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, \DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * A link to a tweet is read through the mirror that serves OpenGraph tags
     * for it.
     */
    private static function mirrorUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !\in_array(strtolower($parts['host'] ?? ''), self::TWITTER_HOSTS, true)) {
            return $url;
        }

        if ('' === ($parts['path'] ?? '') || '/' === $parts['path']) {
            return $url;
        }

        return preg_replace(
            '#^(https?://)(www\.)?(twitter|x)\.com#i',
            '$1'.self::MIRROR_HOST,
            $url,
        ) ?? $url;
    }
}
