<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Entity\Account;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The manifest that lets a browser install the application.
 *
 * It is served rather than written to a file because the name and the icon are
 * the ones the administrator chose for the account. Reading it is public: a
 * browser asks for it before anyone has signed in.
 */
final class PwaController extends AbstractController
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    #[Route('/webmanifest', name: 'pwa_manifest', methods: ['GET'])]
    public function manifest(): Response
    {
        $response = $this->render('pwa/manifest.json.twig', [
            'name' => $this->accounts->findOneBy([])?->getName() ?? Account::DEFAULT_NAME,
        ]);

        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }
}
