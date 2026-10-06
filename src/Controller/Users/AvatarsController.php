<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\ActionText\SignedId;
use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\ActiveStorage\StockImages;
use App\ActiveStorage\Variants;
use App\Entity\ActiveStorageBlob;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Avatar of a user.
 *
 * The address carries a signed identifier rather than the identifier of the
 * user, and the response is cached in the browser for half an hour. A user
 * without a picture gets a generated one, drawn from their initials, so the
 * address always answers with an image.
 */
final class AvatarsController extends AbstractController
{
    private const CACHE_SECONDS = 1800;
    private const STALE_SECONDS = 604800;

    public function __construct(
        private readonly SignedId $signedIds,
        private readonly UserRepository $users,
        private readonly Attachments $attachments,
        private readonly Variants $variants,
        private readonly BlobStorage $storage,
        private readonly StockImages $stockImages,
    ) {
    }

    #[Route('/users/{user_id}/avatar', name: 'user_avatar_show', methods: ['GET'], requirements: ['user_id' => '[A-Za-z0-9_=-]+'])]
    public function show(Request $request, string $user_id): Response
    {
        $user = $this->userFromToken($user_id);

        if (null === $user) {
            throw $this->createNotFoundException();
        }

        $response = new Response();
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setStaleWhileRevalidate(self::STALE_SECONDS);
        $response->setEtag(md5(\sprintf('%s-%s', $user->getId(), $user->getUpdatedAt()->format('U.u'))));

        if ($response->isNotModified($request)) {
            return $response;
        }

        if (null !== $variant = $this->avatarVariant($user)) {
            $response->setContent($this->storage->read($variant));
            $response->headers->set('Content-Type', 'image/webp');

            return $response;
        }

        if ($user->isBot()) {
            $response->setContent($this->stockImages->contents(StockImages::BOT_AVATAR));
            $response->headers->set('Content-Type', 'image/svg+xml');

            return $response;
        }

        $response = $this->render('users/avatars/show.svg.twig', ['user' => $user], $response);
        $response->headers->set('Content-Type', 'image/svg+xml');

        return $response;
    }

    /**
     * Drops the avatar of the signed in user. The address carries an identifier
     * of the user it belongs to, which is ignored: only your own picture can be
     * removed.
     */
    #[Route('/users/{user_id}/avatar', name: 'user_avatar_destroy', methods: ['DELETE'], requirements: ['user_id' => '[A-Za-z0-9_=-]+'])]
    #[IsCsrfTokenValid('user_avatar_destroy', tokenKey: '_csrf_token')]
    public function destroy(Request $request, string $user_id, #[CurrentUser] User $user): Response
    {
        $this->attachments->purge($user, Attachments::AVATAR);

        return $this->redirectToRoute('user_profile_show');
    }

    private function avatarVariant(User $user): ?ActiveStorageBlob
    {
        $avatar = $this->attachments->blobFor($user, Attachments::AVATAR);

        return null === $avatar ? null : $this->variants->of($avatar, Variants::SQUARE);
    }

    private function userFromToken(string $token): ?User
    {
        $decoded = $this->signedIds->decode($token);

        if (null === $decoded || 'User' !== $decoded['model'] || SignedId::PURPOSE_AVATAR !== $decoded['purpose']) {
            return null;
        }

        return $this->users->find((int) $decoded['id']);
    }
}
