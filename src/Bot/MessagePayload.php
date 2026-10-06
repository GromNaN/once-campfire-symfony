<?php

declare(strict_types=1);

namespace App\Bot;

use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\User;
use App\Message\MessageBody;
use App\Twig\AvatarExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The JSON form of a message and of a boost, which is what a bot reads.
 *
 * The keys and the shape are the ones the original application renders with
 * jbuilder, so a bot written against it keeps working: the creation time is
 * ISO 8601 in UTC with milliseconds, a body carries both its text and its
 * HTML, a creator carries its role, and every address is written in full.
 */
final class MessagePayload
{
    public function __construct(
        private readonly MessageBody $body,
        private readonly AvatarExtension $avatars,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function message(Message $message): array
    {
        $room = $message->getRoom();
        $creator = $message->getCreator();

        return [
            'id' => $message->getId(),
            'created_at' => $this->timestamp($message->getCreatedAt()),
            'body' => [
                'plain_text' => $this->body->plainText($message),
                // The markup a page shows, which is what the original sends: a
                // bot that reads a message gets the same HTML a reader gets.
                'html' => $this->body->html($message),
            ],
            'creator' => $this->user($creator),
            'room' => ['id' => $room?->getId()],
            'url' => $this->urlGenerator->generate(
                'rooms_messages_show',
                ['room_id' => $room?->getId(), 'id' => $message->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }

    public function boost(Boost $boost): array
    {
        $message = $boost->getMessage();

        return [
            'id' => $boost->getId(),
            'content' => $boost->getContent(),
            'created_at' => $this->timestamp($boost->getCreatedAt()),
            'booster' => $this->user($boost->getBooster()),
            'message' => [
                'id' => $message?->getId(),
                'url' => $this->urlGenerator->generate(
                    'rooms_messages_show',
                    ['room_id' => $message?->getRoom()?->getId(), 'id' => $message?->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
        ];
    }

    private function user(?User $user): array
    {
        return [
            'id' => $user?->getId(),
            'name' => $user?->getName(),
            'role' => $user?->getRole()->label(),
            'avatar_url' => null === $user ? null : $this->avatars->url($user),
        ];
    }

    /**
     * The form Rails writes a time in: ISO 8601 in UTC, milliseconds included.
     */
    private function timestamp(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
