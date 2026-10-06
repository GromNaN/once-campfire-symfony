<?php

declare(strict_types=1);

namespace App\Controller\Messages;

use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\User;
use App\Form\BoostType;
use App\Mercure\RoomBroadcast;
use App\Repository\BoostRepository;
use App\Security\Voter\ViewMessageVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The short reactions added to a message.
 *
 * The list and the form live in Turbo Frames of their own under the message,
 * so adding a boost redraws the list without touching the rest of the page.
 * Only the person who added a boost can take it back.
 */
final class BoostsController extends AbstractController
{
    private const MESSAGE_REQUIREMENTS = ['message_id' => '\d+'];

    public function __construct(
        private readonly BoostRepository $boosts,
        private readonly RoomBroadcast $broadcast,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/messages/{message_id}/boosts', name: 'messages_boosts_index', methods: ['GET'], requirements: self::MESSAGE_REQUIREMENTS)]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    public function index(#[MapEntity(id: 'message_id')] Message $message): Response
    {
        return $this->render('rooms/messages/boosts/index.html.twig', ['message' => $message]);
    }

    #[Route('/messages/{message_id}/boosts/new', name: 'messages_boosts_new', methods: ['GET'], requirements: self::MESSAGE_REQUIREMENTS)]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    public function new(#[MapEntity(id: 'message_id')] Message $message): Response
    {
        return $this->render('rooms/messages/boosts/new.html.twig', [
            'message' => $message,
            'form' => $this->createForm(BoostType::class, new Boost()),
        ]);
    }

    #[Route('/messages/{message_id}/boosts', name: 'messages_boosts_create', methods: ['POST'], requirements: self::MESSAGE_REQUIREMENTS)]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    public function create(Request $request, #[MapEntity(id: 'message_id')] Message $message, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(BoostType::class, $boost = new Boost());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render(
                'rooms/messages/boosts/new.html.twig',
                ['message' => $message, 'form' => $form],
                new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        $boost->setMessage($message);
        $boost->setBooster($user);

        $this->entityManager->persist($boost);
        $this->entityManager->flush();

        $this->broadcast->boostCreated($boost);

        // The form asks for the list back, so the answer is the list itself.
        return $this->redirectToRoute('messages_boosts_index', ['message_id' => $message->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/messages/{message_id}/boosts/{id}', name: 'messages_boosts_destroy', methods: ['DELETE'], requirements: self::MESSAGE_REQUIREMENTS + ['id' => '\d+'])]
    #[IsGranted(ViewMessageVoter::CAN_VIEW, subject: 'message', statusCode: Response::HTTP_NOT_FOUND)]
    #[IsCsrfTokenValid('messages_boosts_destroy', tokenKey: '_csrf_token')]
    public function destroy(#[MapEntity(id: 'message_id')] Message $message, int $id, #[CurrentUser] User $user): Response
    {
        $boost = $this->boosts->findOneBy(['id' => $id, 'message' => $message, 'booster' => $user]);

        if (null === $boost) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($boost);
        $this->entityManager->flush();

        $this->broadcast->boostRemoved($boost);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
