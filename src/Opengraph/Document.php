<?php

declare(strict_types=1);

namespace App\Opengraph;

/**
 * The OpenGraph tags of a page.
 *
 * A page says what it is with meta tags whose property or name starts with
 * "og:". Only the four the preview shows are kept, and when a page declares the
 * same one twice the last wins, which is what the original application does
 * when it builds a hash out of them.
 */
final class Document
{
    /**
     * The tags a preview is built from.
     */
    public const ATTRIBUTES = ['title', 'url', 'image', 'description'];

    private const PREFIX = 'og:';

    private function __construct(private readonly \DOMDocument $document)
    {
    }

    /**
     * Reads a page.
     *
     * The bytes are turned into UTF-8 first, using the encoding the page
     * declares and falling back to UTF-8 when it declares none, so that a title
     * with an accent in it survives the trip.
     */
    public static function fromHtml(string $html): self
    {
        $document = new \DOMDocument();

        $errors = libxml_use_internal_errors(true);

        // The declaration is what tells the parser the bytes are UTF-8: without
        // it, it reads them as Latin-1 and every accent comes out wrong.
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.self::toUtf8($html),
            \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return new self($document);
    }

    /**
     * The OpenGraph attributes of the page, keyed by their name without the
     * "og:" prefix. A tag without content says nothing and is left out.
     *
     * @return array<string, string>
     */
    public function opengraphAttributes(): array
    {
        $attributes = [];

        foreach ($this->metaTags() as $tag) {
            $name = $tag->hasAttribute('property') ? $tag->getAttribute('property') : $tag->getAttribute('name');
            $name = substr($name, \strlen(self::PREFIX));

            if (!\in_array($name, self::ATTRIBUTES, true)) {
                continue;
            }

            $content = trim($tag->getAttribute('content'));

            if ('' !== $content) {
                $attributes[$name] = $content;
            }
        }

        return $attributes;
    }

    /**
     * @return list<\DOMElement>
     */
    private function metaTags(): array
    {
        $xpath = new \DOMXPath($this->document);

        $nodes = $xpath->query('//meta[starts-with(@property, "og:") or starts-with(@name, "og:")]');

        if (false === $nodes) {
            return [];
        }

        $tags = [];

        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $tags[] = $node;
            }
        }

        return $tags;
    }

    private static function toUtf8(string $html): string
    {
        $charset = self::declaredCharset($html);

        if ('utf-8' === $charset) {
            return $html;
        }

        try {
            return (string) mb_convert_encoding($html, 'UTF-8', $charset);
        } catch (\ValueError) {
            // A page may declare an encoding that is not known here. Reading it
            // as it stands is better than showing nothing at all.
            return $html;
        }
    }

    private static function declaredCharset(string $html): string
    {
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-zA-Z0-9._-]+)/i', $html, $matches)) {
            return strtolower($matches[1]);
        }

        return 'utf-8';
    }
}
