<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Entity\Account;
use App\Form\CustomStylesType;
use App\Form\Data\CustomStylesData;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The CSS an administrator adds to the account.
 *
 * It is served in a style element on every page, so it is only reachable by an
 * administrator: whatever is written here runs in the browser of every member.
 */
final class CustomStylesController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/account/custom_styles/edit', name: 'account_custom_styles_edit', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(): Response
    {
        return $this->page($this->createForm(CustomStylesType::class, $this->data()));
    }

    #[Route('/account/custom_styles', name: 'account_custom_styles_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Request $request): Response
    {
        $form = $this->createForm(CustomStylesType::class, $data = $this->data());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $account = $this->account();
        $account->setCustomStyles($this->blankToNull($data->customStyles));
        $this->entityManager->flush();

        return $this->redirectToRoute('account_custom_styles_edit');
    }

    private function data(): CustomStylesData
    {
        $data = new CustomStylesData();
        $data->customStyles = $this->account()->getCustomStyles();

        return $data;
    }

    private function page(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('accounts/custom_styles/edit.html.twig', ['form' => $form], new Response('', $status));
    }

    /**
     * A field emptied by the administrator removes the styles rather than
     * storing an empty string, so the account page can tell the difference.
     */
    private function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : $value;
    }

    private function account(): Account
    {
        $account = $this->accounts->findOneBy([]);
        \assert(null !== $account);

        return $account;
    }
}
