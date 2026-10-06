<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Bot\WebhookDelivery;
use App\Message\DeliverWebhook;
use App\Repository\MessageRepository;
use App\Repository\WebhookRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Calls the endpoint of a bot, and posts what it answers.
 *
 * Both records are read again here rather than carried in the message: the
 * announcement happens after the request that posted the message, so the bot
 * is told about the message as it is when the worker runs. A record deleted in
 * the meantime has nothing to announce.
 */
#[AsMessageHandler]
final class DeliverWebhookHandler
{
    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly MessageRepository $messages,
        private readonly WebhookDelivery $delivery,
    ) {
    }

    public function __invoke(DeliverWebhook $message): void
    {
        $webhook = $this->webhooks->find($message->webhookId);
        $posted = $this->messages->find($message->messageId);

        if (null === $webhook || null === $posted) {
            return;
        }

        $this->delivery->deliver($webhook, $posted);
    }
}
