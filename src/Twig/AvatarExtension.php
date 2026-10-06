<?php

declare(strict_types=1);

namespace App\Twig;

use App\ActionText\SignedId;
use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Builds the address of a user avatar.
 *
 * The address carries a signed identifier instead of the user identifier, so
 * the token can be handed to a browser without exposing anything else, and a
 * version parameter taken from updated_at, which changes the address whenever
 * the picture does. Both are what the original application does.
 */
final class AvatarExtension
{
    /**
     * Colours of the generated avatars, in the order the original lists them.
     *
     * @var list<string>
     */
    private const COLORS = [
        '#AF2E1B', '#CC6324', '#3B4B59', '#BFA07A', '#ED8008', '#ED3F1C',
        '#BF1B1B', '#736B1E', '#D07B53', '#736356', '#AD1D1D', '#BF7C2A',
        '#C09C6F', '#698F9C', '#7C956B', '#5D618F', '#3B3633', '#67695E',
    ];

    public function __construct(
        private readonly SignedId $signedIds,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsTwigFunction('avatar_path')]
    public function path(User $user): string
    {
        return $this->generate($user, UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /**
     * The same address, written in full. A bot reads its payload outside the
     * application, so an address without a host would be of no use to it.
     */
    public function url(User $user): string
    {
        return $this->generate($user, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * The colour of a generated avatar. It is drawn from the identifier, so a
     * user keeps the same colour everywhere.
     */
    #[AsTwigFunction('avatar_background_color')]
    public function backgroundColor(User $user): string
    {
        return self::COLORS[crc32((string) $user->getId()) % \count(self::COLORS)];
    }

    public function token(User $user): string
    {
        return $this->signedIds->encode('User', (int) $user->getId(), SignedId::PURPOSE_AVATAR);
    }

    private function generate(User $user, int $referenceType): string
    {
        return $this->urlGenerator->generate('user_avatar_show', [
            'user_id' => $this->token($user),
            'v' => $this->version($user),
        ], $referenceType);
    }

    /**
     * Seconds and milliseconds since the epoch, which is the version Rails
     * writes in the address.
     */
    private function version(User $user): string
    {
        return $user->getUpdatedAt()->format('Uv');
    }
}
