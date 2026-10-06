<?php

declare(strict_types=1);

namespace App\Bot;

use App\ActionText\RichTextRepository;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Webhook;
use App\Message\MessageBody;
use App\Message\MessageFiles;
use App\Message\SendPushNotification;
use App\Mercure\RoomBroadcast;
use App\Search\MessageIndex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Announces a message to the endpoint of a bot, and posts whatever comes back.
 *
 * The endpoint is called with the details of the message and of the room, and
 * it answers with the text or the file the bot wants to post in return, which
 * is what makes a bot a member of the room rather than a service beside it. An
 * endpoint that does not answer within a few seconds is not left silent: the
 * bot says so in the room.
 *
 * The address is set by an administrator, who may legitimately point a bot at
 * a service on their own network, so unlike a link preview this request is not
 * kept away from private addresses.
 */
final class WebhookDelivery
{
    public const ENDPOINT_TIMEOUT = 7;

    /**
     * The most of an answer that is read. A bot is a service the application
     * does not control, and an answer of unbounded size would otherwise be held
     * whole in the memory of the worker. Five megabytes leaves room for the
     * documents a bot legitimately posts back.
     */
    public const MAX_BODY_BYTES = 5 * 1024 * 1024;

    private const TEXT_CONTENT_TYPES = ['text/html', 'text/plain'];

    public function __construct(
        private readonly WebhookClient $client,
        private readonly MessageBody $body,
        private readonly MessageFiles $files,
        private readonly RichTextRepository $richTexts,
        private readonly MessageIndex $index,
        private readonly RoomBroadcast $broadcast,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MimeTypesInterface $mimeTypes,
    ) {
    }

    public function deliver(Webhook $webhook, Message $message): void
    {
        $bot = $webhook->getUser();
        $url = $webhook->getUrl();

        if (null === $bot || null === $url || '' === $url) {
            return;
        }

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $this->payload($webhook, $message, $bot),
                'timeout' => self::ENDPOINT_TIMEOUT,
                // The original application calls the endpoint once and reads the
                // answer as it comes: a redirect is not followed, it is a code
                // that is not 200 and nothing is posted back.
                'max_redirects' => 0,
            ]);

            $status = $response->getStatusCode();
            $contentType = $this->contentType($response);
        } catch (TimeoutExceptionInterface) {
            $this->reply($webhook, $message, sprintf('Failed to respond within %d seconds', self::ENDPOINT_TIMEOUT));

            return;
        } catch (TransportExceptionInterface) {
            // A name that does not resolve, a refused connection or a broken
            // handshake is a failed delivery, not a reason to try again. The
            // original application makes a single attempt.
            return;
        }

        if (200 !== $status) {
            return;
        }

        // The body is read outside the call above, so a timeout that happens
        // while the answer is being read is caught here rather than left to fail
        // the job: the bot is told it was too slow, as it is when it never
        // answers at all.
        try {
            $contents = $this->readBody($response);
        } catch (TimeoutExceptionInterface) {
            $this->reply($webhook, $message, sprintf('Failed to respond within %d seconds', self::ENDPOINT_TIMEOUT));

            return;
        } catch (TransportExceptionInterface) {
            return;
        }

        if (null !== $contentType && \in_array($contentType, self::TEXT_CONTENT_TYPES, true)) {
            $this->reply($webhook, $message, $contents);

            return;
        }

        $extension = $this->extensionFor($contentType);

        if (null !== $extension) {
            $this->replyWithFile($webhook, $message, $contents, $contentType, $extension);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Webhook $webhook, Message $message, User $bot): array
    {
        $room = $message->getRoom();
        $creator = $message->getCreator();

        return [
            'user' => [
                'id' => $creator?->getId(),
                'name' => $creator?->getName(),
            ],
            'room' => [
                'id' => $room?->getId(),
                'name' => $room?->getName(),
                'path' => $this->urlGenerator->generate(
                    'room_bot_messages_index',
                    ['room_id' => $room?->getId(), 'bot_key' => $bot->getBotKey()],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
            ],
            'message' => [
                'id' => $message->getId(),
                'body' => [
                    'html' => $this->body->stored($message),
                    'plain' => $this->withoutOwnMention($message, $bot),
                ],
                'path' => $this->urlGenerator->generate(
                    'rooms_at_message',
                    ['room_id' => $room?->getId(), 'message_id' => $message->getId()],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
            ],
        ];
    }

    /**
     * The text of a message with the mentions of the bot that receives it taken
     * out, so that a bot answering "@bot do this" is not told to do it again.
     */
    private function withoutOwnMention(Message $message, User $bot): string
    {
        $text = str_replace('@'.$bot->getName(), '', $this->body->plainText($message));

        return preg_replace('/\A\p{Space}+|\p{Space}+\z/u', '', $text) ?? $text;
    }

    private function reply(Webhook $webhook, Message $message, string $text): void
    {
        $reply = $this->createReply($webhook, $message);

        if (null === $reply) {
            return;
        }

        $this->richTexts->setBody((int) $reply->getId(), $text);
        $this->publish($reply);
    }

    private function replyWithFile(Webhook $webhook, Message $message, string $contents, string $contentType, string $extension): void
    {
        $reply = $this->createReply($webhook, $message);

        if (null === $reply) {
            return;
        }

        $this->files->attach($reply, $contents, 'attachment.'.$extension, $contentType);
        $this->publish($reply);
    }

    private function createReply(Webhook $webhook, Message $message): ?Message
    {
        $room = $message->getRoom();
        $bot = $webhook->getUser();

        if (null === $room || null === $bot) {
            return null;
        }

        $reply = new Message();
        $reply->setRoom($room);
        $reply->setCreator($bot);

        $this->entityManager->persist($reply);
        $this->entityManager->flush();

        return $reply;
    }

    private function publish(Message $reply): void
    {
        // A reply is a message like any other: it counts as activity in the room
        // and it notifies the readers who are away, exactly as a message posted
        // through the composer does.
        $reply->getRoom()?->touch();
        $this->entityManager->flush();

        $this->index->index($reply);
        $this->broadcast->messageCreated($reply);
        $this->bus->dispatch(new SendPushNotification((int) $reply->getId()));
    }

    /**
     * Reads at most MAX_BODY_BYTES of the answer.
     *
     * The body is streamed rather than read whole, so an endpoint that answers
     * with an endless or enormous body cannot take the worker down with it.
     */
    private function readBody(ResponseInterface $response): string
    {
        $stream = $response->toStream(false);

        try {
            $contents = stream_get_contents($stream, self::MAX_BODY_BYTES);
        } finally {
            fclose($stream);
        }

        return false === $contents ? '' : $contents;
    }

    /**
     * The media type of the answer, without the parameters a server may append
     * to it. Rails reads the same value from Net::HTTP.
     */
    private function contentType(ResponseInterface $response): ?string
    {
        $header = $response->getHeaders(false)['content-type'][0] ?? null;

        if (null === $header) {
            return null;
        }

        return strtolower(trim(preg_split('/\s*;\s*/', $header)[0] ?? ''));
    }

    /**
     * The extension of a media type the application knows, or null when it is
     * not one, in which case the answer is ignored.
     */
    private function extensionFor(?string $contentType): ?string
    {
        if (null === $contentType || '' === $contentType) {
            return null;
        }

        return $this->mimeTypes->getExtensions($contentType)[0] ?? null;
    }
}
