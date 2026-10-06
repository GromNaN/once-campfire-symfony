<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\ActionText\RichTextRepository;
use App\Entity\Message;
use App\Entity\User;
use App\Form\Data\MessageData;
use App\Form\MessageType;
use App\Http\Attribute\MapRoomMessage;
use App\Http\TurboStream;
use App\Message\MessagePage;
use App\Message\MessageWriter;
use App\Message\RemoveMessage;
use App\Repository\RoomRepository;
use App\Security\Voter\AdministerVoter;
use App\Security\Voter\ViewMessageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Messages of a room.
 *
 * The list is paginated by walking from a message: before returns the page
 * older than it, after the page newer than it, and neither returns the newest
 * page. A Turbo Stream request gets the appended element, a plain request gets
 * a redirect.
 */
final class MessagesController extends AbstractController
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly RichTextRepository $richTexts,
        private readonly MessagePage $page,
        private readonly MessageWriter $writer,
        private readonly RemoveMessage $removeMessage,
    ) {
    }

    #[Route('/rooms/{room_id}/messages', name: 'rooms_messages_index', methods: ['GET'], requirements: ['room_id' => '\d+'])]
    public function index(Request $request, int $room_id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($room_id, $user);

        if (null === $room) {
            return $this->render('rooms/messages/room_not_found.html.twig');
        }

        $messages = $this->page->load($room, $request->query->getString('before'), $request->query->getString('after'));

        if ([] === $messages) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('rooms/messages/index.html.twig', [
            'room' => $room,
            'messages' => $messages,
        ]);
    }

    #[Route('/rooms/{room_id}/messages', name: 'rooms_messages_create', methods: ['POST'], requirements: ['room_id' => '\d+'])]
    public function create(Request $request, int $room_id, #[CurrentUser] User $user): Response
    {
        $room = $this->rooms->findForUser($room_id, $user);

        if (null === $room) {
            return $this->render('rooms/messages/room_not_found.html.twig');
        }

        $form = $this->createForm(MessageType::class, $data = new MessageData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $message = $this->writer->create($room, $user, $data->body, $data->attachment, $data->clientMessageId);

            return $this->turboStream($request, 'rooms/messages/create.stream.twig', [
                'room' => $room,
                'message' => $message,
            ]);
        }

        return $this->render(
            'rooms/messages/new.html.twig',
            ['room' => $room, 'form' => $form],
            new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    #[Route('/rooms/{room_id}/messages/{id}', name: 'rooms_messages_show', methods: ['GET'], requirements: ['room_id' => '\d+', 'id' => '\d+'])]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    public function show(#[MapRoomMessage] Message $message): Response
    {
        return $this->render('rooms/messages/show.html.twig', [
            'room' => $message->getRoom(),
            'message' => $message,
        ]);
    }

    #[Route('/rooms/{room_id}/messages/{id}/edit', name: 'rooms_messages_edit', methods: ['GET'], requirements: ['room_id' => '\d+', 'id' => '\d+'])]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER, subject: 'message')]
    public function edit(#[MapRoomMessage] Message $message): Response
    {
        $data = new MessageData();
        $data->body = $this->richTexts->bodyFor((int) $message->getId());

        return $this->render('rooms/messages/edit.html.twig', [
            'room' => $message->getRoom(),
            'message' => $message,
            // The update route only takes PUT, so the form has to say so and
            // let the method override turn its post into a put.
            'form' => $this->createForm(MessageType::class, $data, ['method' => 'PUT']),
        ]);
    }

    #[Route('/rooms/{room_id}/messages/{id}', name: 'rooms_messages_update', methods: ['PUT', 'PATCH'], requirements: ['room_id' => '\d+', 'id' => '\d+'])]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER, subject: 'message')]
    public function update(Request $request, #[MapRoomMessage] Message $message): Response
    {
        $form = $this->createForm(MessageType::class, $data = new MessageData(), ['method' => 'PUT']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->update($message, $data->body, $data->attachment);

            return $this->turboStream($request, 'rooms/messages/update.stream.twig', [
                'room' => $message->getRoom(),
                'message' => $message,
            ]);
        }

        return $this->render(
            'rooms/messages/edit.html.twig',
            ['room' => $message->getRoom(), 'message' => $message, 'form' => $form],
            new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    #[Route('/rooms/{room_id}/messages/{id}', name: 'rooms_messages_destroy', methods: ['DELETE'], requirements: ['room_id' => '\d+', 'id' => '\d+'])]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER, subject: 'message')]
    public function destroy(Request $request, #[MapRoomMessage] Message $message): Response
    {
        $room = $message->getRoom();
        $this->removeMessage->remove($message);

        return $this->turboStream($request, 'rooms/messages/destroy.stream.twig', [
            'room' => $room,
            'message' => $message,
        ]);
    }

    /**
     * Answers with a Turbo Stream when the client asks for one, and with an
     * empty page otherwise, which is what a plain form post gets.
     *
     * @param array<string, mixed> $context
     */
    private function turboStream(Request $request, string $template, array $context): Response
    {
        if (!TurboStream::wants($request)) {
            return new Response('', Response::HTTP_OK);
        }

        // Turbo announces that it accepts a stream on every form post, so the
        // announcement alone is not enough to label the answer: this is the
        // point where the answer really is a stream.
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render($template, $context);
    }
}
