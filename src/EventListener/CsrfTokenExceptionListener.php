<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * Turns a refused form of a signed in member into a refusal rather than a
 * sign in.
 *
 * The attribute that checks the token of a hand written form answers with an
 * authentication exception, and the firewall answers an authentication
 * exception by sending the visitor to the sign in page. That is the right
 * answer on a sign in form, where a stale token means the sign in did not
 * happen, and the wrong answer everywhere else: a member who sends a delete
 * form from a page that has been open for too long is already signed in, and
 * sending them to the sign in page hides the real problem.
 *
 * A visitor who is not signed in keeps the sign in page, because for them the
 * missing sign in is the real problem.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 10)]
final class CsrfTokenExceptionListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof InvalidCsrfTokenException) {
            return;
        }

        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return;
        }

        // The refused token is not kept as the cause: the firewall walks the
        // chain of causes of an exception and answers an authentication
        // exception wherever it finds one, which would put the redirect back.
        $event->setThrowable(new AccessDeniedHttpException($exception->getMessage()));
    }
}
