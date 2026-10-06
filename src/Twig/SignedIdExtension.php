<?php

declare(strict_types=1);

namespace App\Twig;

use App\ActionText\SignedId;
use App\Rails\RailsModelName;
use Twig\Attribute\AsTwigFunction;

/**
 * Makes the signed identifiers the rich text body carries.
 *
 * A mention is stored as an attachment element holding the signed identifier
 * of its user, which is also what the original application writes. The
 * identifier is what ties the stored body to the record, so it is produced in
 * one place only.
 */
final class SignedIdExtension
{
    public function __construct(private readonly SignedId $signedIds)
    {
    }

    /**
     * The identifier an attachment in a rich text body points at.
     */
    #[AsTwigFunction('attachable_sgid')]
    public function attachableSgid(object $record): string
    {
        return $this->signedId($record, SignedId::PURPOSE_ATTACHABLE);
    }

    /**
     * The identifier of an uploaded file, which is also what makes its address
     * unguessable.
     */
    #[AsTwigFunction('blob_sgid')]
    public function blobSgid(object $record): string
    {
        return $this->signedId($record, SignedId::PURPOSE_BLOB);
    }

    /**
     * The identifier of a record for a given purpose, which expires after the
     * number of seconds given when one is given. A sign in link does expire,
     * which is why the lifetime can be asked for here.
     */
    #[AsTwigFunction('signed_id')]
    public function signedId(object $record, string $purpose, ?int $expiresIn = null): string
    {
        $id = method_exists($record, 'getId') ? $record->getId() : null;

        if (null === $id) {
            throw new \InvalidArgumentException(\sprintf('%s cannot be signed because it has no identifier.', $record::class));
        }

        return $this->signedIds->encode(RailsModelName::of($record), (int) $id, $purpose, $expiresIn);
    }
}
