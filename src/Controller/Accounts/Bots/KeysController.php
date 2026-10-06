<?php

declare(strict_types=1);

namespace App\Controller\Accounts\Bots;

use App\Repository\UserRepository;
use App\Security\Voter\AdministerVoter;
use App\Service\BotSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Hands a bot a new key.
 *
 * The key is part of the address the bot calls, so replacing it is how an
 * administrator stops a key that leaked without having to recreate the bot.
 */
final class KeysController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly BotSetup $bots,
    ) {
    }

    #[Route('/account/bots/{bot_id}/key', name: 'account_bot_key_update', methods: ['PUT'], requirements: ['bot_id' => '\d+'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER)]
    #[IsCsrfTokenValid('account_bot_key_update', tokenKey: '_csrf_token')]
    public function update(int $bot_id): Response
    {
        $bot = $this->users->findActiveBot($bot_id);

        if (null === $bot) {
            throw $this->createNotFoundException();
        }

        $this->bots->resetKey($bot);

        return $this->redirectToRoute('account_bots_index');
    }
}
