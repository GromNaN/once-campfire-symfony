<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\Entity\ActiveStorageBlob;
use App\Entity\Room;
use App\Entity\User;
use App\Form\BotType;
use App\Form\Data\BotData;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Service\BotSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The chat bots of the account: the list, the form, and the two actions that
 * retire a bot or hand it a new key.
 *
 * The list shows, for every bot, the commands that post into the rooms it
 * belongs to. They are built here rather than in the template because they
 * carry an absolute address: a bot runs outside the application, so a path
 * without a host would be of no use to it.
 */
final class BotsController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly Attachments $attachments,
        private readonly BlobStorage $storage,
        private readonly BotSetup $bots,
    ) {
    }

    #[Route('/account/bots', name: 'account_bots_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        $bots = [];

        foreach ($this->users->findActiveBotsOrdered() as $bot) {
            $bots[] = [
                'bot' => $bot,
                'has_avatar' => null !== $this->attachments->blobFor($bot, Attachments::AVATAR),
                'commands' => $this->commands($bot),
            ];
        }

        return $this->render('accounts/bots/index.html.twig', ['bots' => $bots]);
    }

    #[Route('/account/bots/new', name: 'account_bot_new', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(): Response
    {
        return $this->render('accounts/bots/new.html.twig', [
            'form' => $this->createForm(BotType::class, new BotData(), $this->formOptions('account_bots_create')),
        ]);
    }

    #[Route('/account/bots', name: 'account_bots_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): Response
    {
        $form = $this->createForm(BotType::class, $data = new BotData(), $this->formOptions('account_bots_create'));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('accounts/bots/new.html.twig', ['form' => $form], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $bot = $this->bots->create((string) $data->name, $data->webhookUrl);
        $this->storeAvatar($bot, $data->avatar);

        return $this->redirectToRoute('account_bots_index');
    }

    #[Route('/account/bots/{id}/edit', name: 'account_bot_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(int $id): Response
    {
        $bot = $this->bot($id);

        return $this->render('accounts/bots/edit.html.twig', [
            'bot' => $bot,
            'form' => $this->createForm(BotType::class, $this->botData($bot), $this->formOptions('account_bot_update', $bot)),
        ]);
    }

    #[Route('/account/bots/{id}', name: 'account_bot_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Request $request, int $id): Response
    {
        $bot = $this->bot($id);
        $form = $this->createForm(BotType::class, $data = $this->botData($bot), $this->formOptions('account_bot_update', $bot));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('accounts/bots/edit.html.twig', ['bot' => $bot, 'form' => $form], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->bots->update($bot, (string) $data->name, $data->webhookUrl);
        $this->storeAvatar($bot, $data->avatar);

        return $this->redirectToRoute('account_bots_index');
    }

    #[Route('/account/bots/{id}', name: 'account_bot_destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('account_bot_destroy', tokenKey: '_csrf_token')]
    public function destroy(int $id): Response
    {
        $this->bots->deactivate($this->bot($id));

        return $this->redirectToRoute('account_bots_index');
    }

    /**
     * The address a bot form posts to, and the verb it saves with.
     *
     * The form is shown on a page of its own, which is not where it saves, so
     * the address is passed in rather than left to the browser.
     *
     * @return array{action: string, method: string}
     */
    private function formOptions(string $route, ?User $bot = null): array
    {
        $parameters = null === $bot ? [] : ['id' => $bot->getId()];

        return [
            'action' => $this->generateUrl($route, $parameters),
            'method' => null === $bot ? 'POST' : 'PUT',
        ];
    }

    private function bot(int $id): User
    {
        $bot = $this->users->findActiveBot($id);

        if (null === $bot) {
            throw $this->createNotFoundException();
        }

        return $bot;
    }

    private function botData(User $bot): BotData
    {
        $data = new BotData();
        $data->name = $bot->getName();
        $data->webhookUrl = $bot->getWebhook()?->getUrl();

        return $data;
    }

    private function storeAvatar(User $bot, ?UploadedFile $avatar): void
    {
        if (null === $avatar) {
            return;
        }

        $contents = (string) file_get_contents($avatar->getPathname());

        $this->attachments->replace(
            $this->storage->store($contents, $avatar->getClientOriginalName(), $avatar->getMimeType()),
            $bot,
            Attachments::AVATAR,
        );
    }

    /**
     * The commands that post into the rooms of a bot, keyed by room.
     *
     * @return list<array{room: Room, message: string, upload: string}>
     */
    private function commands(User $bot): array
    {
        $commands = [];

        foreach ($this->memberships->findAllForUserOrdered($bot) as $membership) {
            $room = $membership->getRoom();

            if (null === $room || $room->isDirect()) {
                continue;
            }

            $url = $this->generateUrl('room_bot_messages_create', [
                'room_id' => $room->getId(),
                'bot_key' => $bot->getBotKey(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $commands[] = [
                'room' => $room,
                'message' => \sprintf("curl -d 'Hello!' %s", $url),
                'upload' => \sprintf('curl -F "attachment=@/path/to/file" %s', $url),
            ];
        }

        return $commands;
    }
}
