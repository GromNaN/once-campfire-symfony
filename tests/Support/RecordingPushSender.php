<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PushSubscription;
use App\Push\PushPayload;
use App\Push\PushSender;

/**
 * A push sender that writes down what it was asked to send.
 *
 * A real notification is encrypted before it leaves the process, so a test
 * cannot read what a reader was told out of the request it produced. This
 * stands in for the sender and keeps the payload as the application built it.
 */
final class RecordingPushSender implements PushSender
{
    /**
     * @var list<array{subscriptions: list<PushSubscription>, payload: PushPayload}>
     */
    private array $sent = [];

    public function send(array $subscriptions, PushPayload $payload): void
    {
        $this->sent[] = ['subscriptions' => $subscriptions, 'payload' => $payload];
    }

    public function count(): int
    {
        return \count($this->sent);
    }

    public function lastPayload(): ?PushPayload
    {
        return [] === $this->sent ? null : $this->sent[array_key_last($this->sent)]['payload'];
    }

    /**
     * The endpoint of every subscription a notification was sent to.
     *
     * @return list<string>
     */
    public function endpoints(): array
    {
        $endpoints = [];

        foreach ($this->sent as $entry) {
            foreach ($entry['subscriptions'] as $subscription) {
                $endpoints[] = (string) $subscription->getEndpoint();
            }
        }

        return $endpoints;
    }
}
