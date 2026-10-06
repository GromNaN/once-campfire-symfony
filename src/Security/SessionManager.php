<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Session;
use App\Entity\User;
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Starts and ends the sessions of a user.
 *
 * The framework session only carries an opaque token. Everything else, the
 * device, the address and the last activity, lives in the sessions table so a
 * user can review and revoke the devices they are signed in on.
 */
final class SessionManager
{
    private const RETURN_TO_KEY = 'campfire.return_to';

    /**
     * How long a device may stay untouched before it is signed out, when the
     * framework session cookie has no lifetime of its own.
     */
    private const DEFAULT_IDLE_TIMEOUT = 1209600;

    /**
     * How long a device may stay signed in at all, however often it is used.
     */
    private const ABSOLUTE_TIMEOUT = 2592000;

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'session.metadata.cookie_lifetime')]
        private readonly ?int $cookieLifetime = null,
    ) {
    }

    public function start(User $user, Request $request): Session
    {
        $session = new Session();
        $session->setUser($user);
        $session->setToken(Session::generateToken());
        $session->setUserAgent($request->headers->get('User-Agent'));
        $session->setIpAddress($request->getClientIp());
        $session->setLastActiveAt($this->clock->now());

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->storeToken($request, $session->getToken());

        return $session;
    }

    public function end(Request $request): void
    {
        $token = $this->token($request);

        if (null !== $token) {
            $session = $this->sessions->findOneBy(['token' => $token]);

            if (null !== $session) {
                $this->entityManager->remove($session);
                $this->entityManager->flush();
            }
        }

        // The security context listener writes the token back into the session
        // on the way out, so the token has to be dropped as well. Without this
        // the visitor stays signed in after a sign out.
        $this->tokenStorage->setToken(null);

        $request->getSession()->remove(SessionAuthenticator::SESSION_KEY);
        $request->getSession()->invalidate();
    }

    /**
     * Signs a device out once it has been idle for too long, or once it has
     * been signed in for too long, before the firewall authenticates it.
     *
     * A browser that presents the session cookie is the only one that pays for
     * the lookup, so an anonymous visit stays as cheap as it was.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
    public function endExpiredSession(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession() || !$request->cookies->has($request->getSession()->getName())) {
            return;
        }

        $session = $this->current($request);

        if (null === $session || !$this->isExpired($session)) {
            return;
        }

        $this->entityManager->remove($session);
        $this->entityManager->flush();

        $request->getSession()->remove(SessionAuthenticator::SESSION_KEY);
        $this->tokenStorage->setToken(null);
    }

    /**
     * Whether a device has been idle for too long, or signed in for too long.
     */
    public function isExpired(Session $session): bool
    {
        $now = $this->clock->now();

        if ($now->getTimestamp() - $session->getLastActiveAt()->getTimestamp() > $this->idleTimeout()) {
            return true;
        }

        return $now->getTimestamp() - $session->getCreatedAt()->getTimestamp() > self::ABSOLUTE_TIMEOUT;
    }

    public function current(Request $request): ?Session
    {
        $token = $this->token($request);

        return null === $token ? null : $this->sessions->findOneBy(['token' => $token]);
    }

    public function token(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $token = $request->getSession()->get(SessionAuthenticator::SESSION_KEY);

        return \is_string($token) ? $token : null;
    }

    public function storeToken(Request $request, string $token): void
    {
        $session = $request->getSession();
        $session->set(SessionAuthenticator::SESSION_KEY, $token);

        // Rotating the session id on login prevents a fixed session attack.
        $session->migrate(true);
    }

    /**
     * Remembers where a signed out visitor wanted to go, so that signing in
     * sends them there instead of the root page.
     */
    public function storeReturnTo(Request $request, string $url): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::RETURN_TO_KEY, $url);
        }
    }

    public function popReturnTo(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $url = $request->getSession()->get(self::RETURN_TO_KEY);
        $request->getSession()->remove(self::RETURN_TO_KEY);

        return \is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * Removes every session of a user, which is what happens when an account is
     * banned or deactivated.
     */
    public function endAllFor(User $user): void
    {
        $this->sessions->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function cookieName(): string
    {
        return 'campfire_session';
    }

    public function deleteCookie(Response $response): void
    {
        $response->headers->clearCookie($this->cookieName());
    }

    /**
     * The lifetime of the framework session cookie when it has one, so an idle
     * device is signed out no later than the cookie disappears.
     */
    private function idleTimeout(): int
    {
        return null !== $this->cookieLifetime && $this->cookieLifetime > 0
            ? $this->cookieLifetime
            : self::DEFAULT_IDLE_TIMEOUT;
    }
}
