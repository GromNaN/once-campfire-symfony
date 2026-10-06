<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds the version headers that the original application sets on every
 * response, so a client can tell which build is serving it.
 */
#[AsEventListener(event: ResponseEvent::class)]
final class VersionHeadersListener
{
    public function __construct(
        #[Autowire('%campfire.app_version%')]
        private readonly string $appVersion = '',
        #[Autowire('%campfire.git_revision%')]
        private readonly string $gitRevision = '',
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Version', '' === $this->appVersion ? '0' : $this->appVersion);
        $headers->set('X-Rev', $this->gitRevision);
    }
}
