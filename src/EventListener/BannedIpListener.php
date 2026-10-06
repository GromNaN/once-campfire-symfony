<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\BanRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Refuses state changing requests coming from a banned address.
 *
 * Reading a page stays allowed so that a banned visitor is not locked out of
 * the sign in page.
 */
#[AsEventListener(event: RequestEvent::class, priority: 40)]
final class BannedIpListener
{
    public function __construct(private readonly BanRepository $bans)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->isMethodSafe()) {
            return;
        }

        if ($this->bans->isBanned($request->getClientIp())) {
            $event->setResponse(new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        }
    }
}
