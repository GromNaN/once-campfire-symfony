<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Bot\MessagePayload;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use App\Message\MessagePage;
use App\Message\MessageWriter;
use App\Message\RemoveMessage;
use App\Repository\MessageRepository;
use App\Repository\RoomRepository;
use App\Security\Voter\AdministerVoter;
use App\Security\Voter\ViewMessageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The messages of a room, read and written by a bot.
 *
 * A bot calls these addresses with its key in the path, which is the only
 * credential it needs. The shape of the answers is the one the original
 * application renders, down to the headers: the list carries the total number
 * of messages and a link to the next page, and a page is walked from a message
 * rather than from an offset.
 *
 * A message is posted by sending its text as the request body, or by uploading
 * a file, in which case the text is left out.
 */
final class BotMessagesController extends AbstractController
{
    private const REQUIREMENTS = ['room_id' => '\d+', 'bot_key' => '\d+-[A-Za-z0-9]+'];

    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly MessageRepository $messages,
        private readonly MessagePage $page,
        private readonly MessagePayload $payload,
        private readonly MessageWriter $writer,
        private readonly RemoveMessage $removeMessage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages', name: 'room_bot_messages_index', methods: ['GET'], requirements: self::REQUIREMENTS)]
    public function index(Request $request, int $room_id, string $bot_key, #[CurrentUser] User $bot): Response
    {
        $room = $this->rooms->findForUser($room_id, $bot);

        if (null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $messages = $this->page->load($room, $request->query->getString('before'), $request->query->getString('after'));

        $response = new JsonResponse(array_map($this->payload->message(...), $messages));
        $response->headers->set('X-Total-Count', (string) $this->messages->countInRoom($room));

        if (null !== $link = $this->nextPageLink($request, $room, $bot_key, $messages)) {
            $response->headers->set('Link', $link);
        }

        return $response;
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages', name: 'room_bot_messages_create', methods: ['POST'], requirements: self::REQUIREMENTS)]
    public function create(Request $request, int $room_id, string $bot_key, #[CurrentUser] User $bot): Response
    {
        $room = $this->rooms->findForUser($room_id, $bot);

        if (null === $room) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $attachment = $request->files->get('attachment');
        $body = null === $attachment ? $request->getContent() : '';

        if (null === $attachment && '' === trim($body)) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = $this->writer->create($room, $bot, $body, $attachment);

        return new Response('', Response::HTTP_CREATED, [
            'Location' => $this->urlGenerator->generate(
                'rooms_messages_show',
                ['room_id' => $room->getId(), 'id' => $message->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ]);
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages/{id}', name: 'room_bot_messages_show', methods: ['GET'], requirements: self::REQUIREMENTS + ['id' => '\d+'])]
    public function show(int $room_id, string $bot_key, int $id, #[CurrentUser] User $bot): Response
    {
        $message = $this->findMessage($room_id, $id, $bot);

        if (null === $message) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ViewMessageVoter::CAN_VIEW, $message);

        return new JsonResponse($this->payload->message($message));
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages/{id}', name: 'room_bot_messages_update', methods: ['PUT', 'PATCH'], requirements: self::REQUIREMENTS + ['id' => '\d+'])]
    public function update(Request $request, int $room_id, string $bot_key, int $id, #[CurrentUser] User $bot): Response
    {
        $message = $this->findMessage($room_id, $id, $bot);

        if (null === $message) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(AdministerVoter::CAN_ADMINISTER, $message);

        $attachment = $request->files->get('attachment');
        $this->writer->update($message, null === $attachment ? $request->getContent() : null, $attachment);

        return new JsonResponse($this->payload->message($message));
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages/{id}', name: 'room_bot_messages_destroy', methods: ['DELETE'], requirements: self::REQUIREMENTS + ['id' => '\d+'])]
    public function destroy(int $room_id, string $bot_key, int $id, #[CurrentUser] User $bot): Response
    {
        $message = $this->findMessage($room_id, $id, $bot);

        if (null === $message) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(AdministerVoter::CAN_ADMINISTER, $message);
        $this->removeMessage->remove($message);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * The message the address points at, when the bot is a member of the room
     * it belongs to. A bot that is not in the room sees nothing at all.
     */
    private function findMessage(int $room_id, int $id, User $bot): ?Message
    {
        $room = $this->rooms->findForUser($room_id, $bot);

        return null === $room ? null : $this->messages->findInRoom($room, $id);
    }

    /**
     * The address of the page that follows the one that was just read.
     *
     * A page is walked from a message, so the link carries the message to walk
     * from: the newest one of the page when the caller asked for what comes
     * after a message, and the oldest one otherwise. There is no link when
     * there is nothing further in that direction.
     *
     * @param list<Message> $messages
     */
    private function nextPageLink(Request $request, Room $room, string $bot_key, array $messages): ?string
    {
        if ([] === $messages) {
            return null;
        }

        if ('' !== $request->query->getString('after')) {
            $edge = $messages[array_key_last($messages)];

            if (!$this->messages->hasAfter($room, $edge)) {
                return null;
            }

            $parameters = ['after' => $edge->getId()];
        } else {
            $edge = $messages[0];

            if (!$this->messages->hasBefore($room, $edge)) {
                return null;
            }

            $parameters = ['before' => $edge->getId()];
        }

        return sprintf('<%s>; rel="next"', $this->urlGenerator->generate(
            'room_bot_messages_index',
            ['room_id' => $room->getId(), 'bot_key' => $bot_key] + $parameters,
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
