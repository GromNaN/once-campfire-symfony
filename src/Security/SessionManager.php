<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Session;
use App\Entity\User;
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function start(User $user, Request $request): Session
    {
        $session = new Session();
        $session->setUser($user);
        $session->setToken(Session::generateToken());
        $session->setUserAgent($request->headers->get('User-Agent'));
        $session->setIpAddress($request->getClientIp());
        $session->setLastActiveAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

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
}
