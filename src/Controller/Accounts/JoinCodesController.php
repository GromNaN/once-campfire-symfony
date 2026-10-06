<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Repository\AccountRepository;
use App\Service\AccountAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Regenerates the code of the join link.
 *
 * The link an administrator has already handed out stops working, which is the
 * point: it is how a link that went somewhere it should not have is retired.
 */
final class JoinCodesController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountAdministration $administration,
    ) {
    }

    #[Route('/account/join_code', name: 'account_join_code_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('account_join_code_create', tokenKey: '_csrf_token')]
    public function create(): Response
    {
        $account = $this->accounts->findOneBy([]);

        if (null === $account) {
            throw $this->createNotFoundException();
        }

        $this->administration->resetJoinCode($account);

        return $this->redirectToRoute('account_edit');
    }
}
