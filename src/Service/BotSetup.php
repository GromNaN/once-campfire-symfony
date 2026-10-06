<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\WebhookRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, edits and retires the bots of an account.
 *
 * A bot is a user of the account with the bot role, which is what lets it be a
 * member of rooms and write messages like anyone else. What makes it a bot is
 * the token in its key, the only credential it has, and the webhook it is told
 * through when a room it belongs to gets a message.
 */
final class BotSetup
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebhookRepository $webhooks,
        private readonly OpenRoomMembership $openRooms,
    ) {
    }

    /**
     * Creates a bot and joins it to the open rooms, the way any new user of the
     * account joins them.
     */
    public function create(string $name, ?string $webhookUrl): User
    {
        $bot = new User();
        $bot->setName($name);
        $bot->setRole(UserRole::Bot);
        $bot->setStatus(UserStatus::Active);
        $bot->resetBotKey();

        $this->entityManager->persist($bot);
        $this->entityManager->flush();

        $this->setWebhookUrl($bot, $webhookUrl);

        $this->openRooms->join($bot);

        return $bot;
    }

    public function update(User $bot, string $name, ?string $webhookUrl): void
    {
        $bot->setName($name);

        $this->setWebhookUrl($bot, $webhookUrl);

        $this->entityManager->flush();
    }

    /**
     * Retires a bot. Its key stops working, which is what keeps it from posting
     * again, and its webhook is dropped with it.
     */
    public function deactivate(User $bot): void
    {
        $webhook = $this->webhooks->findOneBy(['user' => $bot]);

        if (null !== $webhook) {
            $this->entityManager->remove($webhook);
        }

        $bot->setStatus(UserStatus::Deactivated);

        $this->entityManager->flush();
    }

    /**
     * Hands the bot a new key. Every call the bot makes with the old one starts
     * failing, which is the point of the action.
     */
    public function resetKey(User $bot): void
    {
        $bot->resetBotKey();

        $this->entityManager->flush();
    }

    /**
     * A webhook is only kept when a URL was typed. Clearing the field removes
     * the row rather than leaving a webhook that is never called.
     */
    private function setWebhookUrl(User $bot, ?string $webhookUrl): void
    {
        $url = null === $webhookUrl ? null : trim($webhookUrl);
        $webhook = $this->webhooks->findOneBy(['user' => $bot]);

        if (null === $url || '' === $url) {
            if (null !== $webhook) {
                $this->entityManager->remove($webhook);
                $this->entityManager->flush();
            }

            return;
        }

        if (null === $webhook) {
            $webhook = new Webhook();
            $webhook->setUser($bot);
            $this->entityManager->persist($webhook);
        }

        $webhook->setUrl($url);
        $this->entityManager->flush();
    }
}
