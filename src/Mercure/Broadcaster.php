<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Sends Turbo Streams to the browsers through the Mercure hub.
 *
 * Mercure is publish only, so every realtime interaction of the original
 * ActionCable channels becomes a render here plus a POST from the browser.
 * The stream markup is produced by the same Twig templates the direct Turbo
 * responses use, so a message looks the same whether it arrived over the wire
 * or in the answer to the form post.
 */
final class Broadcaster
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function publish(string $topic, string $template, array $context): void
    {
        $this->hub->publish(new Update($topic, $this->twig->render($template, $context), true));
    }

    /**
     * Publishes the same stream to several topics at once.
     *
     * @param list<string>         $topics
     * @param array<string, mixed> $context
     */
    public function publishTo(array $topics, string $template, array $context): void
    {
        if ([] === $topics) {
            return;
        }

        $this->hub->publish(new Update($topics, $this->twig->render($template, $context), true));
    }
}
