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
 * Creates the account, its first room and its administrator. The page is only
 * reachable while no account exists yet.
 */
final class FirstRunController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountSetup $accountSetup,
        private readonly SessionManager $sessions,
    ) {
    }

    #[Route('/first_run', name: 'first_run', methods: ['GET'])]
    public function show(): Response
    {
        if ($this->accountExists()) {
            return $this->redirectToRoute('root');
        }

        return $this->render('first_run/show.html.twig', [
            'form' => $this->createForm(RegistrationType::class, new RegistrationData()),
        ]);
    }

    #[Route('/first_run', name: 'first_run_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($this->accountExists()) {
            return $this->redirectToRoute('root');
        }

        $form = $this->createForm(RegistrationType::class, $data = new RegistrationData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $this->accountSetup->createFirstRun($data);
            } catch (UniqueConstraintViolationException) {
                // Another request created the account first. Sending the visitor
                // to the root page is what the original application does.
                return $this->redirectToRoute('root');
            }

            $this->sessions->start($user, $request);

            return $this->redirectToRoute('root');
        }

        return $this->render(
            'first_run/show.html.twig',
            ['form' => $form],
            new Response('', Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    private function accountExists(): bool
    {
        return $this->accounts->count([]) > 0;
    }
}
