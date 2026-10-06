<?php

declare(strict_types=1);

namespace App\Push;

use App\Entity\PushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Delivers a notification over the Web Push protocol.
 *
 * The endpoint of a subscription belongs to a browser vendor, but it is still
 * a URL a member typed into the database, so the client this sender is given
 * refuses private addresses: an endpoint pointing back at the network of the
 * server is never called. A subscription the push service reports as gone is
 * deleted, which is what keeps the table from filling with dead endpoints.
 *
 * The library is built on demand rather than injected, because it refuses to
 * be built at all without a key pair and an installation may well run without
 * push configured.
 */
final class WebPushSender implements PushSender
{
    public function __construct(
        #[Autowire(service: 'app.push.psr18_client')]
        private readonly Psr18Client $client,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $privateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $subject,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(array $subscriptions, PushPayload $payload): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $webPush = $this->webPush();
        $queued = [];

        foreach ($subscriptions as $subscription) {
            $endpoint = (string) $subscription->getEndpoint();

            if (!$subscription->isPermittedEndpoint() || '' === $endpoint) {
                continue;
            }

            $queued[$endpoint] = $subscription;

            $webPush->queueNotification(Subscription::create([
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => $subscription->getP256dhKey(),
                    'auth' => $subscription->getAuthKey(),
                ],
            ]), $payload->toJson());
        }

        foreach ($webPush->flush() as $report) {
            $this->report($report, $queued[$report->getEndpoint()] ?? null);
        }
    }

    /**
     * Whether the installation has a key pair, which is what a push service
     * needs to accept a notification.
     */
    public function isConfigured(): bool
    {
        return '' !== trim($this->publicKey) && '' !== trim($this->privateKey);
    }

    private function webPush(): WebPush
    {
        return new WebPush(
            ['VAPID' => [
                'subject' => $this->subject,
                'publicKey' => trim($this->publicKey),
                'privateKey' => trim($this->privateKey),
            ]],
            ['urgency' => 'high'],
            $this->client,
            $this->client,
            $this->client,
        );
    }

    private function report(MessageSentReport $report, ?PushSubscription $subscription): void
    {
        if ($report->isSuccess()) {
            return;
        }

        // A push service answers that the subscription is gone when the
        // browser has thrown it away, which is the moment to stop keeping it.
        if ($report->isSubscriptionExpired() && null !== $subscription) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();

            return;
        }

        $this->logger->warning('A push notification could not be delivered.', [
            'endpoint' => $report->getEndpoint(),
            'reason' => $report->getReason(),
        ]);
    }
}
