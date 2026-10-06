<?php

declare(strict_types=1);

namespace App\Push;

/**
 * What a push notification says.
 *
 * The shape is the one the service worker of the original application reads:
 * a title, the options of the notification, and the path the browser opens
 * when it is clicked. The badge is the number of unread rooms the reader has,
 * which the operating system paints on the icon of the application.
 */
final readonly class PushPayload
{
    public function __construct(
        public string $title,
        public string $body,
        public string $path,
        public int $badge,
        public string $icon,
    ) {
    }

    /**
     * The JSON document the push service delivers to the browser.
     */
    public function toJson(): string
    {
        return json_encode([
            'title' => $this->title,
            'options' => [
                'body' => $this->body,
                'icon' => $this->icon,
                'data' => [
                    'path' => $this->path,
                    'badge' => $this->badge,
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }
}
