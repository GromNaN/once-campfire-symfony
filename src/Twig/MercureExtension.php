<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Mercure\SubscriberTopics;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/**
 * Builds the address the browser opens its Mercure stream on.
 *
 * The hub refuses a subscription that names no topic, so the address carries
 * the topics of the reader. The token that authorizes them is a cookie, which
 * the stream source sends along with its private subscription.
 */
final class MercureExtension
{
    public function __construct(
        private readonly SubscriberTopics $topics,
        private readonly Security $security,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {
    }

    #[AsTwigFunction('mercure_subscribe_url')]
    public function subscribeUrl(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->publicUrl.$this->topics->queryForUser($user) : $this->publicUrl;
    }
}
