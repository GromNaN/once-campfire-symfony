<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Form\Data\PushSubscriptionData;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The push notification subscriptions of the signed in user.
 *
 * A browser registers its endpoint here when the reader turns notifications
 * on, and the page lists what was registered so a reader can send themselves a
 * test notification or drop a device they no longer use. The address carries
 * no identifier, so a member can only ever see and change their own.
 */
final class PushSubscriptionsController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/users/me/push_subscriptions', name: 'push_subscriptions_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('users/push_subscriptions/index.html.twig', [
            'subscriptions' => $this->subscriptions->findBy(['user' => $user], ['id' => 'ASC']),
        ]);
    }

    /**
     * Registers the endpoint of a browser.
     *
     * A browser asks again on every visit, so an endpoint already known is
     * kept and touched rather than stored a second time.
     */
    #[Route('/users/me/push_subscriptions', name: 'push_subscriptions_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')] PushSubscriptionData $data,
        #[CurrentUser] User $user,
        Request $request,
    ): Response {
        $existing = $this->subscriptions->findOneBy([
            'user' => $user,
            'endpoint' => $data->endpoint,
            'p256dhKey' => $data->p256dhKey,
            'authKey' => $data->authKey,
        ]);

        if (null !== $existing) {
            $existing->setUserAgent($request->headers->get('User-Agent'));
            $this->entityManager->flush();

            return new Response('', Response::HTTP_OK);
        }

        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setEndpoint($data->endpoint);
        $subscription->setP256dhKey($data->p256dhKey);
        $subscription->setAuthKey($data->authKey);
        $subscription->setUserAgent($request->headers->get('User-Agent'));

        if (0 !== \count($this->validator->validate($subscription))) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_OK);
    }

    #[Route('/users/me/push_subscriptions/{id}', name: 'push_subscriptions_destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('push_subscriptions_destroy', tokenKey: '_csrf_token')]
    public function destroy(#[CurrentUser] User $user, int $id): Response
    {
        $subscription = $this->subscriptions->findOneBy(['id' => $id, 'user' => $user]);

        // Dropping a device that is already gone is not an error: the reader
        // asked for it to stop, and it has.
        if (null !== $subscription) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('push_subscriptions_index');
    }
}
