<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Authenticates a bot from the "<id>-<token>" key carried in the URL.
 *
 * Bots call the JSON message endpoints with a key that an administrator copies
 * from the account page. The key is the user id, a hyphen, and the bot token,
 * exactly like the original application.
 */
final class BotAuthenticator extends AbstractAuthenticator
{
    public const ROLE_BOT = 'ROLE_BOT';

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function supports(Request $request): ?bool
    {
        $botKey = $request->attributes->get('bot_key');

        return \is_string($botKey) && '' !== $botKey;
    }

    public function authenticate(Request $request): Passport
    {
        $botKey = (string) $request->attributes->get('bot_key');

        $separator = strpos($botKey, '-');

        if (false === $separator) {
            throw new CustomUserMessageAuthenticationException('The bot key is malformed.');
        }

        $id = substr($botKey, 0, $separator);
        $token = substr($botKey, $separator + 1);

        if ('' === $id || '' === $token) {
            throw new CustomUserMessageAuthenticationException('The bot key is malformed.');
        }

        $bot = $this->users->findOneBy(['id' => (int) $id, 'botToken' => $token]);

        if (null === $bot || !$bot->isBot() || !$bot->isActive()) {
            throw new CustomUserMessageAuthenticationException('The bot key is not valid.');
        }

        return new SelfValidatingPassport(
            new UserBadge($bot->getUserIdentifier(), fn () => $bot, [self::ROLE_BOT]),
        );
    }

    /**
     * The token carries the bot role, which is what the access rule of the bot
     * addresses asks for. A bot is not a signed in person: it reaches the JSON
     * endpoints of a room and is turned down everywhere else.
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new PostAuthenticationToken($passport->getUser(), $firewallName, [self::ROLE_BOT]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
