<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\Data\LoginData;
use App\Form\LoginType;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

final class SessionController extends AbstractController
{
    /**
     * Number of sign in attempts allowed per address, matching the original
     * application.
     */
    public const RATE_LIMIT = 10;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionManager $sessions,
        #[Autowire(service: 'limiter.login')]
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    #[Route('/session/new', name: 'session_new', methods: ['GET'])]
    public function new(): Response
    {
        if (0 === $this->users->count([])) {
            return $this->redirectToRoute('first_run');
        }

        return $this->render('session/new.html.twig', [
            'form' => $this->createForm(LoginType::class, new LoginData()),
        ]);
    }

    #[Route('/session', name: 'session_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->loginLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->reject('Too many requests or unauthorized.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $form = $this->createForm(LoginType::class, $data = new LoginData());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->reject('Too many requests or unauthorized.', Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->users->findOneBy(['emailAddress' => $data->emailAddress]);

        if (null === $user || !$user->isActive() || !$this->passwordHasher->isPasswordValid($user, $data->password)) {
            return $this->reject('Too many requests or unauthorized.', Response::HTTP_UNAUTHORIZED);
        }

        $this->sessions->start($user, $request);

        return new RedirectResponse($this->sessions->popReturnTo($request) ?? $this->generateUrl('root'));
    }

    #[Route('/session', name: 'session_destroy', methods: ['DELETE'])]
    #[IsCsrfTokenValid('logout', tokenKey: '_csrf_token')]
    public function destroy(Request $request): Response
    {
        $this->removePushSubscription($request);
        $this->sessions->end($request);

        $response = new RedirectResponse($this->generateUrl('root'));
        $this->sessions->deleteCookie($response);

        return $response;
    }

    /**
     * A device that signs out also stops receiving notifications, so the push
     * subscription it registered is removed.
     */
    private function removePushSubscription(Request $request): void
    {
        $endpoint = $request->request->get('push_subscription_endpoint');
        $user = $this->getUser();

        if (!\is_string($endpoint) || '' === $endpoint || !$user instanceof User) {
            return;
        }

        $subscription = $this->pushSubscriptions->findOneBy(['endpoint' => $endpoint, 'user' => $user]);

        if (null !== $subscription) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
        }
    }

    private function reject(string $message, int $status): Response
    {
        $this->addFlash('alert', $message);

        return $this->render(
            'session/new.html.twig',
            ['form' => $this->createForm(LoginType::class, new LoginData())],
            new Response('', $status),
        );
    }
}
