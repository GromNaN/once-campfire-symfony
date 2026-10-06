<?php

declare(strict_types=1);

namespace App\Controller\Users\PushSubscriptions;

use App\Entity\User;
use App\Push\PushPayload;
use App\Push\PushSender;
use App\Repository\PushSubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Sends a notification to one device, so a reader can tell whether push
 * notifications reach it.
 *
 * The body is a random value rather than a sentence: two test notifications
 * that look the same are hard to tell apart in a list the browser keeps.
 */
final class TestNotificationsController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushSender $sender,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        '/users/me/push_subscriptions/{push_subscription_id}/test_notifications',
        name: 'push_subscription_test_notifications_create',
        methods: ['POST'],
        requirements: ['push_subscription_id' => '\d+'],
    )]
    #[IsCsrfTokenValid('push_subscription_test_notifications_create', tokenKey: '_csrf_token')]
    public function create(#[CurrentUser] User $user, int $push_subscription_id): Response
    {
        $subscription = $this->subscriptions->findOneBy(['id' => $push_subscription_id, 'user' => $user]);

        if (null !== $subscription) {
            $this->sender->send([$subscription], new PushPayload(
                title: 'Campfire Test',
                body: bin2hex(random_bytes(16)),
                path: $this->urlGenerator->generate('push_subscriptions_index', [], UrlGeneratorInterface::ABSOLUTE_PATH),
                badge: 0,
                icon: $this->urlGenerator->generate('account_logo_show', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            ));
        }

        return $this->redirectToRoute('push_subscriptions_index');
    }
}
