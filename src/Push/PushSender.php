<?php

declare(strict_types=1);

namespace App\Push;

use App\Entity\PushSubscription;

/**
 * Hands a notification to the push services of the readers.
 *
 * The sending is kept behind this interface because what a notification says
 * and who it reaches are decided by the application, while how it travels
 * belongs to the push protocol, where the payload is encrypted before it
 * leaves. A test can then say what a reader was told without undoing that
 * encryption.
 */
interface PushSender
{
    /**
     * @param list<PushSubscription> $subscriptions
     */
    public function send(array $subscriptions, PushPayload $payload): void;
}
