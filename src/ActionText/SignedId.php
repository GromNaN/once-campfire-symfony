<?php

declare(strict_types=1);

namespace App\ActionText;

use App\Rails\RailsModelName;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Produces and reads the signed global identifiers ActionText uses to point at
 * attachables such as mentions.
 *
 * The wire format matches Rails: the payload is the strict base64 encoding of a
 * JSON document that holds a global id under "_rails.data", followed by "--" and
 * a signature. The signature is verified by default, so a forged identifier is
 * refused and an expired one is never read. The only unsigned path is for
 * ActionText User mentions, which the original application tolerates so that a
 * rotated secret does not orphan every existing mention. Older Rails 7 payloads,
 * which nest a marshalled global id under "_rails.message", are understood too.
 */
final class SignedId
{
    public const PURPOSE_ATTACHABLE = 'attachable';
    public const PURPOSE_BLOB = 'blob';
    public const PURPOSE_TRANSFER = 'transfer';
    public const PURPOSE_AVATAR = 'avatar';

    /**
     * Application name used in the global id, matching the Rails application.
     */
    public const GID_APP = 'campfire';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * @param string   $railsModel the Rails model name, for example "User" or "ActiveStorage::Blob"
     * @param int|null $expiresIn  lifetime in seconds, or null for a signed id that never expires
     */
    public function encode(
        string $railsModel,
        int|string $id,
        string $purpose = self::PURPOSE_ATTACHABLE,
        ?int $expiresIn = null,
    ): string {
        $gid = $this->globalId($railsModel, $id);

        $payload = json_encode(
            [
                '_rails' => [
                    'data' => $gid,
                    'exp' => null === $expiresIn ? null : time() + $expiresIn,
                    'pur' => $purpose,
                ],
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );

        // The URL safe alphabet is what Rails uses, and it keeps an identifier
        // usable as a path segment, which the avatar does.
        $message = $this->encodeBase64($payload);

        return $message.'--'.$this->sign($message);
    }

    /**
     * Reads a signed id and returns the model name and the identifier it points
     * at. The signature is verified by default, so a forged identifier is
     * refused. Expiry is always enforced when the payload carries one.
     *
     * Passing false for $verifySignature reads the payload without checking the
     * signature. That path exists for ActionText User mentions alone, mirroring
     * the Rails override which tolerates a rotated secret there. It must not be
     * used anywhere a forged identifier could be turned into access.
     *
     * @return array{model: string, id: string, purpose: ?string}|null
     */
    public function decode(string $sgid, bool $verifySignature = true): ?array
    {
        // Rails separates the payload from its signature with "--". The
        // signature is URL safe base64 of a 32 byte HMAC, so it can hold a
        // separator of its own, and neither the first nor the last occurrence
        // marks the boundary on its own. The signature always has the same
        // length, and anchoring on that length is what makes the split
        // unambiguous. A string too short to hold a signature, or one whose
        // separator is not where a signature would start, carries no signature
        // and is only ever forged.
        $signatureLength = \strlen($this->sign(''));
        $message = $sgid;
        $signature = null;

        if (\strlen($sgid) >= $signatureLength + 2 && '--' === substr($sgid, -($signatureLength + 2), 2)) {
            $message = substr($sgid, 0, -($signatureLength + 2));
            $signature = substr($sgid, -$signatureLength);
        }

        if ($verifySignature && (null === $signature || !hash_equals($this->sign($message), $signature))) {
            return null;
        }

        if ('' === $message) {
            return null;
        }

        $payload = $this->decodeBase64($message);

        if (null === $payload) {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $expiresAt = $decoded['_rails']['exp'] ?? null;

        if (\is_int($expiresAt) && $expiresAt < time()) {
            return null;
        }

        $gid = $decoded['_rails']['data'] ?? null;

        if (!\is_string($gid) && isset($decoded['_rails']['message']) && \is_string($decoded['_rails']['message'])) {
            // Rails 7 stored a marshalled global id. The marshalled bytes are not
            // safe to unmarshal, so the global id is extracted with a pattern,
            // exactly like the original implementation does.
            $marshalled = $this->decodeBase64($decoded['_rails']['message']);

            if (null !== $marshalled && 1 === preg_match('#gid://[^/]+/[^/]+/\d+#', $marshalled, $matches)) {
                $gid = $matches[0];
            }
        }

        if (!\is_string($gid)) {
            return null;
        }

        $parsed = $this->parseGlobalId($gid);

        if (null === $parsed) {
            return null;
        }

        $purpose = $decoded['_rails']['pur'] ?? null;

        return [...$parsed, 'purpose' => \is_string($purpose) ? $purpose : null];
    }

    public function globalId(string $class, int|string $id): string
    {
        return \sprintf('gid://%s/%s/%s', self::GID_APP, RailsModelName::of($class), $id);
    }

    /**
     * @return array{model: string, id: string}|null
     */
    public function parseGlobalId(string $gid): ?array
    {
        if (1 !== preg_match('#^gid://([^/]+)/([^/]+)/(\d+)$#', $gid, $matches)) {
            return null;
        }

        return ['model' => $matches[2], 'id' => $matches[3]];
    }

    private function sign(string $message): string
    {
        return $this->encodeBase64(hash_hmac('sha256', $message, $this->secret, true));
    }

    private function encodeBase64(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    private function decodeBase64(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        if (false !== $decoded) {
            return $decoded;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
