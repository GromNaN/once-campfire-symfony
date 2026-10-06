<?php

declare(strict_types=1);

namespace App\Controller;

use App\ActionText\SignedId;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\SessionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Signs a user in on another device from a link they opened there.
 *
 * The link carries a signed identifier of the user that expires after four
 * hours, which is what the original application issues.
 */
final class SessionTransferController extends AbstractController
{
    public const LINK_LIFETIME = 14400;

    public function __construct(
        private readonly SignedId $signedId,
        private readonly UserRepository $users,
        private readonly SessionManager $sessions,
    ) {
    }

    #[Route('/session/transfers/{id}', name: 'session_transfer', methods: ['GET'])]
    public function show(string $id): Response
    {
        if (null === $this->findUser($id)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        return $this->render('session/transfers/show.html.twig', ['id' => $id]);
    }

    #[Route('/session/transfers/{id}', name: 'session_transfer_update', methods: ['PUT', 'PATCH'])]
    #[IsCsrfTokenValid('session_transfer', tokenKey: '_csrf_token')]
    public function update(Request $request, string $id): Response
    {
        $user = $this->findUser($id);

        if (null === $user) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->sessions->start($user, $request);

        return new RedirectResponse($this->sessions->popReturnTo($request) ?? $this->generateUrl('root'));
    }

    private function findUser(string $id): ?User
    {
        $decoded = $this->signedId->decode($id);

        if (null === $decoded || 'User' !== $decoded['model'] || SignedId::PURPOSE_TRANSFER !== $decoded['purpose']) {
            return null;
        }

        $user = $this->users->find((int) $decoded['id']);

        return $user?->isActive() === true ? $user : null;
    }
}
