<?php

declare(strict_types=1);

namespace App\ActionText;

use App\Entity\ActiveStorageBlob;
use App\Entity\User;
use App\Rails\RailsModelName;
use App\Repository\ActiveStorageBlobRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Reads the attachment elements of a stored rich text body.
 *
 * A body keeps a mention or a link preview as an action-text-attachment
 * element holding the signed identifier of the record it points at. The
 * original application swaps those elements for a partial when it renders the
 * body, and so does this class, from the templates under action_text. The same
 * elements are also what tells the text of a body apart from its markup, and
 * which people a body mentions.
 *
 * A link preview is only kept when it names another host over http or https.
 * A body is written by a member but read by everyone in the room, so a preview
 * aimed back at this application would have every reader's browser fetch it
 * with their session attached.
 */
final class AttachableRenderer
{
    public const MENTION_CONTENT_TYPE = 'application/vnd.campfire.mention';
    public const OPENGRAPH_EMBED_CONTENT_TYPE = 'application/vnd.actiontext.opengraph-embed';

    public const TAG_NAME = 'action-text-attachment';

    /**
     * The image of a Twitter link preview is a profile picture, shown round.
     */
    private const TWITTER_AVATAR_URL_PREFIX = 'https://pbs.twimg.com/profile_images';

    private const TITLE_LENGTH = 280;
    private const DESCRIPTION_LENGTH = 560;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ActiveStorageBlobRepository $blobs,
        private readonly SignedId $signedIds,
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function render(string $html): string
    {
        if (!str_contains($html, '<'.self::TAG_NAME)) {
            return $html;
        }

        return $this->rewrite($html, fn (\DOMElement $node): ?string => $this->markupFor($node));
    }

    /**
     * The text form of a body, which is what a bot payload or a notification
     * carries. A mention reads as "@name" and a file as its name, exactly like
     * the plain text form of the original.
     */
    public function plainText(string $html): string
    {
        $text = $this->rewrite($html, fn (\DOMElement $node): ?string => $this->textFor($node));
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5);

        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
    }

    /**
     * The users a body mentions, each of them once, in the order they appear.
     *
     * This is what decides which bots a new message is announced to, so a
     * mention that no longer resolves to a user is simply left out.
     *
     * @return list<User>
     */
    public function mentionedUsers(string $html): array
    {
        if (!str_contains($html, '<'.self::TAG_NAME)) {
            return [];
        }

        $loaded = $this->load($html);

        if (null === $loaded) {
            return [];
        }

        [, $root] = $loaded;
        $users = [];

        foreach ($root->getElementsByTagName(self::TAG_NAME) as $node) {
            if (!$node instanceof \DOMElement || self::MENTION_CONTENT_TYPE !== $node->getAttribute('content-type')) {
                continue;
            }

            $user = $this->userFor($node);

            if (null !== $user) {
                $users[(int) $user->getId()] = $user;
            }
        }

        return array_values($users);
    }

    private function markupFor(\DOMElement $node): ?string
    {
        $contentType = $node->getAttribute('content-type');

        if (self::MENTION_CONTENT_TYPE === $contentType) {
            return $this->mention($node);
        }

        if (self::OPENGRAPH_EMBED_CONTENT_TYPE === $contentType) {
            return $this->embed($node);
        }

        $blob = $this->blobFor($node);

        return null === $blob
            ? null
            : $this->twig->render('action_text/attachables/_blob.html.twig', ['blob' => $blob]);
    }

    private function textFor(\DOMElement $node): ?string
    {
        $contentType = $node->getAttribute('content-type');

        if (self::MENTION_CONTENT_TYPE === $contentType) {
            $user = $this->userFor($node);

            return null === $user ? null : $this->escape('@'.$user->getName());
        }

        if (self::OPENGRAPH_EMBED_CONTENT_TYPE === $contentType) {
            // A preview adds nothing to the text of a message, which is also
            // what the original decides.
            return '';
        }

        return $this->escape($this->blobFor($node)?->getFilename() ?? '');
    }

    private function mention(\DOMElement $node): ?string
    {
        $user = $this->userFor($node);

        return null === $user
            ? null
            : $this->twig->render('users/_mention.html.twig', ['user' => $user]);
    }

    private function userFor(\DOMElement $node): ?User
    {
        // A mention is read without its signature, which mirrors the Rails
        // override that tolerates a rotated secret for User attachments.
        $decoded = $this->decode($node, verifySignature: false);

        if (null === $decoded || RailsModelName::USER !== $decoded['model']) {
            return null;
        }

        return $this->users->find((int) $decoded['id']);
    }

    private function blobFor(\DOMElement $node): ?ActiveStorageBlob
    {
        $decoded = $this->decode($node);

        if (null === $decoded || RailsModelName::BLOB !== $decoded['model']) {
            return null;
        }

        return $this->blobs->find((int) $decoded['id']);
    }

    /**
     * @return array{model: string, id: string, purpose: ?string}|null
     */
    private function decode(\DOMElement $node, bool $verifySignature = true): ?array
    {
        $sgid = $node->getAttribute('sgid');

        return '' === $sgid ? null : $this->signedIds->decode($sgid, $verifySignature);
    }

    private function embed(\DOMElement $node): ?string
    {
        $attributes = $this->embedAttributes($node);
        $title = trim((string) $attributes['filename']);

        if ('' === $title) {
            return null;
        }

        return $this->twig->render('action_text/attachables/_opengraph_embed.html.twig', [
            'href' => $attributes['href'],
            'url' => $attributes['url'],
            'title' => $this->truncate($title, self::TITLE_LENGTH),
            'description' => $this->truncate(trim((string) $attributes['description']), self::DESCRIPTION_LENGTH),
            'twitter_avatar' => str_starts_with((string) $attributes['url'], self::TWITTER_AVATAR_URL_PREFIX),
        ]);
    }

    /**
     * The details of a preview, taken from the attributes of the element, or
     * from its content when it only carries the markup the editor shows.
     *
     * @return array{href: ?string, url: ?string, filename: ?string, description: ?string}
     */
    private function embedAttributes(\DOMElement $node): array
    {
        if ('' !== $node->getAttribute('filename')) {
            return [
                'href' => $this->externalUrl($node->getAttribute('href')),
                'url' => $this->externalUrl($node->getAttribute('url')),
                'filename' => $node->getAttribute('filename'),
                'description' => $node->getAttribute('caption'),
            ];
        }

        $content = $this->load($node->getAttribute('content'));
        $root = $content[1] ?? null;

        if (null === $root) {
            return ['href' => null, 'url' => null, 'filename' => null, 'description' => null];
        }

        $title = $this->firstOfClass($root, 'og-embed__title');
        $link = null === $title ? null : $title->getElementsByTagName('a')->item(0);
        $image = $this->firstOfClass($root, 'og-embed__image');
        $description = $this->firstOfClass($root, 'og-embed__description');

        return [
            'href' => $this->externalUrl($link?->getAttribute('href')),
            'url' => $this->externalUrl($image?->getElementsByTagName('img')->item(0)?->getAttribute('src')),
            'filename' => null === ($link ?? $title) ? null : trim(($link ?? $title)->textContent),
            'description' => null === $description ? null : trim($description->textContent),
        ];
    }

    private function firstOfClass(\DOMElement $root, string $class): ?\DOMElement
    {
        foreach ($root->getElementsByTagName('*') as $node) {
            if ($node instanceof \DOMElement && $this->hasClass($node, $class)) {
                return $node;
            }
        }

        return null;
    }

    private function hasClass(\DOMElement $node, string $class): bool
    {
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

        return \in_array($class, $classes, true);
    }

    /**
     * Keeps an address only when it names another host over http or https.
     * "https:/rooms/1" parses as https with no host, and a browser resolves
     * that against the origin the application is served from, so an address
     * without a host is dropped too. A percent escape hides this host from the
     * comparison while a browser still unescapes it back here, so an escaped
     * host is out as well.
     */
    private function externalUrl(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $parts = parse_url($value);

        if (!\is_array($parts) || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        return $this->elsewhere($parts['host'] ?? '') ? $value : null;
    }

    /**
     * A preview names a page on the public internet, so its host is a domain
     * name, written plainly. A bare address is not one, and a browser rewrites
     * the many spellings of an address ("2130706433", "0x7f.0.0.1") into a
     * single one before it fetches, which is a race a comparison here loses.
     */
    private function elsewhere(string $host): bool
    {
        if (!$this->namedHost($host)) {
            return false;
        }

        return $this->canonicalHost($host) !== $this->canonicalHost($this->requestStack->getMainRequest()?->getHost() ?? '');
    }

    private function namedHost(string $host): bool
    {
        if ('' === $host || str_contains($host, '%') || !str_contains($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);
        $ending = (string) end($labels);

        // What keeps a name from reading as an address is its last label, which
        // is a word: never a number, and never the hexadecimal spelling of one.
        return 1 === preg_match('/[a-z]/i', $ending) && 1 !== preg_match('/\A0x/i', $ending);
    }

    private function canonicalHost(string $host): string
    {
        return strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
    }

    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 1).'…';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param callable(\DOMElement): ?string $replacement
     */
    private function rewrite(string $html, callable $replacement): string
    {
        $loaded = $this->load($html);

        if (null === $loaded) {
            return $html;
        }

        [$document, $root] = $loaded;

        foreach (iterator_to_array($root->getElementsByTagName(self::TAG_NAME)) as $node) {
            $parent = $node->parentNode;

            if (null === $parent) {
                continue;
            }

            $markup = $replacement($node);

            if (null === $markup) {
                $parent->removeChild($node);

                continue;
            }

            $parent->replaceChild($this->fragment($document, $markup), $node);
        }

        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= (string) $document->saveHTML($child);
        }

        return $result;
    }

    /**
     * Loads an HTML fragment wrapped in an element, so that a body made of
     * several top level elements survives the trip.
     *
     * @return array{\DOMDocument, \DOMElement}|null
     */
    private function load(string $html): ?array
    {
        if ('' === trim($html)) {
            return null;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-campfire-fragment>'.$html.'</div>',
                \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $loaded ? $document->getElementsByTagName('div')->item(0) : null;

        return null === $root ? null : [$document, $root];
    }

    private function fragment(\DOMDocument $document, string $html): \DOMNode
    {
        $fragment = $document->createDocumentFragment();
        $loaded = $this->load($html);

        if (null === $loaded) {
            return $fragment;
        }

        [$source, $sourceRoot] = $loaded;

        foreach ($sourceRoot->childNodes as $child) {
            $fragment->appendChild($document->importNode($child, true));
        }

        return $fragment;
    }
}
