<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Bot\MessagePayload;
use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\User;
use App\Mercure\RoomBroadcast;
use App\Repository\BoostRepository;
use App\Repository\MessageRepository;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The boosts of a message, added and taken back by a bot.
 *
 * A boost is sent as the request body, which is what makes a bot able to react
 * to a message it was told about. A bot only ever takes back its own boosts,
 * and an empty body is refused rather than stored as an empty reaction.
 */
final class BotBoostsController extends AbstractController
{
    private const REQUIREMENTS = ['room_id' => '\d+', 'bot_key' => '\d+-[A-Za-z0-9]+', 'message_id' => '\d+'];

    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly MessageRepository $messages,
        private readonly BoostRepository $boosts,
        private readonly MessagePayload $payload,
        private readonly RoomBroadcast $broadcast,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages/{message_id}/boosts', name: 'room_bot_boosts_create', methods: ['POST'], requirements: self::REQUIREMENTS)]
    public function create(Request $request, int $room_id, string $bot_key, int $message_id, #[CurrentUser] User $bot): Response
    {
        $message = $this->findMessage($room_id, $message_id, $bot);

        if (null === $message) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $content = $request->getContent();

        if ('' === trim($content)) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $boost = new Boost();
        $boost->setMessage($message);
        $boost->setBooster($bot);
        $boost->setContent($content);

        $this->entityManager->persist($boost);
        $this->entityManager->flush();

        $this->broadcast->boostCreated($boost);

        return new JsonResponse($this->payload->boost($boost), Response::HTTP_CREATED);
    }

    #[Route('/rooms/{room_id}/{bot_key}/messages/{message_id}/boosts/{id}', name: 'room_bot_boosts_destroy', methods: ['DELETE'], requirements: self::REQUIREMENTS + ['id' => '\d+'])]
    public function destroy(int $room_id, string $bot_key, int $message_id, int $id, #[CurrentUser] User $bot): Response
    {
        $message = $this->findMessage($room_id, $message_id, $bot);

        if (null === $message) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $boost = $this->boosts->findOneBy(['id' => $id, 'message' => $message, 'booster' => $bot]);

        if (null === $boost) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($boost);
        $this->entityManager->flush();

        $this->broadcast->boostRemoved($boost);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function findMessage(int $room_id, int $message_id, User $bot): ?Message
    {
        $room = $this->rooms->findForUser($room_id, $bot);

        return null === $room ? null : $this->messages->findInRoom($room, $message_id);
    }
}
