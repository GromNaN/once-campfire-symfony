<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Mercure\SubscriberTopics;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Hands the browser a Mercure subscriber token for the topics it may read.
 *
 * Mercure only delivers a topic to a subscriber whose token grants it, so the
 * grant is rebuilt on every response from the memberships of the user. A
 * membership that is revoked therefore stops the stream at the next page load,
 * which is the guarantee the original application gets from authorizing its
 * ActionCable subscriptions in the channel.
 */
#[AsEventListener(event: ResponseEvent::class, priority: -20)]
final class MercureAuthorizationListener
{
    public function __construct(
        private readonly TokenFactoryInterface $defaultFactory,
        private readonly HubInterface $hub,
        private readonly Security $security,
        private readonly SubscriberTopics $topics,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $token = $this->defaultFactory->create([
            new Grant([Grant::ACTION_SUBSCRIBE], $this->topics->forUser($user)),
        ]);

        $event->getResponse()->headers->setCookie(
            Cookie::create($this->hub->getCookieName(), $token)
                ->withPath('/.well-known/mercure')
                ->withHttpOnly(true)
                ->withSecure($request->isSecure())
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }
}
