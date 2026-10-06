<?php

declare(strict_types=1);

namespace App\ActionText;

use App\Rails\RailsModelName;

/**
 * Produces and reads the signed global identifiers ActionText uses to point at
 * attachables such as mentions.
 *
 * The wire format matches Rails: the payload is the strict base64 encoding of a
 * JSON document that holds a global id under "_rails.data", followed by "--" and
 * a signature. Reading is deliberately permissive, exactly like the original
 * application, which ignores the signature for User attachments so that a
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

    public function __construct(private readonly string $secret)
    {
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
     * at. The signature is not enforced, which mirrors the tolerant behaviour of
     * the original implementation.
     *
     * @return array{model: string, id: string, purpose: ?string}|null
     */
    public function decode(string $sgid, bool $verifyExpiry = false): ?array
    {
        $message = explode('--', $sgid, 2)[0] ?? '';

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

        if ($verifyExpiry && \is_int($expiresAt) && $expiresAt < time()) {
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
