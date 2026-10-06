<?php

declare(strict_types=1);

namespace App\ActionText;

use App\Entity\ActionTextRichText;
use App\Repository\ActionTextRichTextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads and writes the rich text body of a record.
 *
 * ActionText stores the body in a polymorphic table instead of on the record
 * itself, so messages are saved first and their body is written right after,
 * which is also what the original application does.
 *
 * A page shows a whole page of messages, so reading each body with a query of
 * its own would be an N+1. The bodies read once are kept here, and a page asks
 * for all of them at once through primeFor(). The cache is dropped between
 * requests, because the application runs in worker mode.
 */
#[AutoconfigureTag('kernel.reset', ['method' => 'reset'])]
final class RichTextRepository implements ResetInterface
{
    public const MESSAGE_RECORD_TYPE = 'Message';
    public const BODY_NAME = 'body';

    /**
     * The bodies already read, keyed by record type and identifier. A null
     * value means the record has no body, which is different from not read.
     *
     * @var array<string, ActionTextRichText|null>
     */
    private array $cache = [];

    /**
     * The sanitizer is the one configured under the name "campfire", which is
     * what the argument name asks for. The name has to be exactly the name of
     * the sanitizer: with anything appended, the container falls back to the
     * default sanitizer, which knows neither the attachment element nor the
     * classes a message body carries.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActionTextRichTextRepository $richTexts,
        private readonly HtmlSanitizerInterface $campfire,
    ) {
    }

    /**
     * Returns the stored HTML body, or an empty string when there is none.
     */
    public function bodyFor(int $recordId, string $recordType = self::MESSAGE_RECORD_TYPE): string
    {
        return $this->find($recordId, $recordType)?->getBody() ?? '';
    }

    public function find(int $recordId, string $recordType = self::MESSAGE_RECORD_TYPE): ?ActionTextRichText
    {
        $key = $this->key($recordId, $recordType);

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->richTexts->findOneBy([
            'recordType' => $recordType,
            'recordId' => $recordId,
            'name' => self::BODY_NAME,
        ]);
    }

    /**
     * Reads the bodies of many records in one query and keeps them, so that
     * the calls that follow do not each ask the database.
     *
     * @param list<int> $recordIds
     */
    public function primeFor(array $recordIds, string $recordType = self::MESSAGE_RECORD_TYPE): void
    {
        $missing = [];

        foreach ($recordIds as $recordId) {
            $key = $this->key($recordId, $recordType);

            if (!\array_key_exists($key, $this->cache)) {
                $missing[$key] = $recordId;
            }
        }

        if ([] === $missing) {
            return;
        }

        // A record with no body is remembered as null, so the page does not ask
        // for it again when it renders.
        foreach (array_keys($missing) as $key) {
            $this->cache[$key] = null;
        }

        foreach ($this->richTexts->findBodiesFor(array_values($missing), $recordType, self::BODY_NAME) as $richText) {
            $this->cache[$this->key($richText->getRecordId(), $richText->getRecordType())] = $richText;
        }
    }

    /**
     * Drops what was read. The application runs in worker mode, so a service
     * lives across requests and the cache must not survive one.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Sanitizes and stores the body. The HTML is cleaned here rather than on
     * render so that what is stored is what is shown.
     */
    public function setBody(int $recordId, string $html, string $recordType = self::MESSAGE_RECORD_TYPE): ActionTextRichText
    {
        $richText = $this->find($recordId, $recordType) ?? new ActionTextRichText();

        $richText
            ->setRecordType($recordType)
            ->setRecordId($recordId)
            ->setName(self::BODY_NAME)
            ->setBody($this->campfire->sanitize($html));

        $this->entityManager->persist($richText);
        $this->entityManager->flush();

        $this->cache[$this->key($recordId, $recordType)] = $richText;

        return $richText;
    }

    public function remove(int $recordId, string $recordType = self::MESSAGE_RECORD_TYPE): void
    {
        $richText = $this->find($recordId, $recordType);

        if (null !== $richText) {
            $this->entityManager->remove($richText);
            $this->entityManager->flush();
        }

        unset($this->cache[$this->key($recordId, $recordType)]);
    }

    private function key(int $recordId, string $recordType): string
    {
        return $recordType.':'.$recordId;
    }
}
