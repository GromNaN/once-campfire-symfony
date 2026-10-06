<?php

declare(strict_types=1);

namespace App\ActionText;

use App\Entity\ActionTextRichText;
use App\Repository\ActionTextRichTextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Reads and writes the rich text body of a record.
 *
 * ActionText stores the body in a polymorphic table instead of on the record
 * itself, so messages are saved first and their body is written right after,
 * which is also what the original application does.
 */
final class RichTextRepository
{
    public const MESSAGE_RECORD_TYPE = 'Message';
    public const BODY_NAME = 'body';

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
        return $this->richTexts->findOneBy([
            'recordType' => $recordType,
            'recordId' => $recordId,
            'name' => self::BODY_NAME,
        ]);
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

        return $richText;
    }

    public function remove(int $recordId, string $recordType = self::MESSAGE_RECORD_TYPE): void
    {
        $richText = $this->find($recordId, $recordType);

        if (null !== $richText) {
            $this->entityManager->remove($richText);
            $this->entityManager->flush();
        }
    }
}
