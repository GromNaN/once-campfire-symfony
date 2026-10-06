<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\Data\RegistrationData;
use App\Form\RegistrationType;
use App\Repository\AccountRepository;
use App\Security\SessionManager;
use App\Service\AccountSetup;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Signup page reached through the join code of the account.
 */
final class JoinController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountSetup $accountSetup,
        private readonly SessionManager $sessions,
    ) {
    }

    #[Route('/join/{join_code}', name: 'join', methods: ['GET'], requirements: ['join_code' => '[A-Za-z0-9-]+'])]
    public function new(string $join_code): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('root');
        }

        if (!$this->joinCodeIsValid($join_code)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->render('join/new.html.twig', [
            'form' => $this->createForm(RegistrationType::class, new RegistrationData()),
            'join_code' => $join_code,
        ]);
    }

    #[Route('/join/{join_code}', name: 'join_create', methods: ['POST'], requirements: ['join_code' => '[A-Za-z0-9-]+'])]
    public function create(Request $request, string $join_code): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('root');
        }

        if (!$this->joinCodeIsValid($join_code)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(RegistrationType::class, $data = new RegistrationData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $this->accountSetup->createMember($data);
            } catch (UniqueConstraintViolationException) {
                // The address already has an account, so the visitor is sent to
                // the sign in page instead.
                return $this->redirectToRoute('session_new');
            }

            $this->sessions->start($user, $request);

            return $this->redirectToRoute('root');
        }

        return $this->render(
            'join/new.html.twig',
            ['form' => $form, 'join_code' => $join_code],
            new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    private function joinCodeIsValid(string $joinCode): bool
    {
        foreach ($this->accounts->findAll() as $account) {
            if (hash_equals($account->getJoinCode(), $joinCode)) {
                return true;
            }
        }

        return false;
    }
}
