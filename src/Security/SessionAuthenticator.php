<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates a request from the session token stored in the framework session.
 *
 * The token points at a row of the sessions table, which is what lets a user
 * review and revoke the devices they are signed in on. A revoked session stops
 * authenticating immediately because the row is gone.
 */
final class SessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const SESSION_KEY = 'campfire_session_token';

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SessionManager $sessionManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        // Starting a session for every anonymous request would be wasteful, so
        // the cookie is checked before the session is opened.
        if (!$request->cookies->has($request->getSession()->getName())) {
            return false;
        }

        return null !== $request->getSession()->get(self::SESSION_KEY);
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->getSession()->get(self::SESSION_KEY);

        $session = \is_string($token) ? $this->sessions->findOneBy(['token' => $token]) : null;

        if (null === $session) {
            $request->getSession()->remove(self::SESSION_KEY);

            throw new CustomUserMessageAuthenticationException('The session is no longer valid.');
        }

        $user = $session->getUser();

        if (null === $user || !$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('The account is not active.');
        }

        $session->resume($request->headers->get('User-Agent'), $request->getClientIp());
        $this->sessions->getEntityManager()->flush();

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn (string $identifier) => $this->users->find((int) $identifier)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        if ($request->isXmlHttpRequest() || 'json' === $request->getRequestFormat(null)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->urlGenerator->generate('session_new'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($request->isXmlHttpRequest() || 'json' === $request->getRequestFormat(null)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        // Safe requests are remembered so that signing in returns the visitor
        // to the page they asked for.
        if ($request->isMethodSafe()) {
            $this->sessionManager->storeReturnTo($request, $request->getRequestUri());
        }

        return new RedirectResponse($this->urlGenerator->generate('session_new'));
    }
}
