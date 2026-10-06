<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\ActiveStorage\StockImages;
use App\ActiveStorage\Variants;
use App\Entity\ActiveStorageBlob;
use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Security\Voter\AdministerVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Logo of the account.
 *
 * The page anyone sees before signing in shows it, so reading it is public. It
 * answers with the icon shipped with the application when no logo was uploaded,
 * in the small size the sidebar asks for or the large one a link preview needs.
 */
final class LogosController extends AbstractController
{
    private const CACHE_SECONDS = 300;
    private const STALE_SECONDS = 604800;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly Attachments $attachments,
        private readonly Variants $variants,
        private readonly BlobStorage $storage,
        private readonly StockImages $stockImages,
    ) {
    }

    #[Route('/account/logo', name: 'account_logo_show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $account = $this->account();
        $small = 'small' === $request->query->get('size');
        $logo = null === $account ? null : $this->attachments->blobFor($account, Attachments::LOGO);
        $stock = $small ? StockImages::APP_ICON_SMALL : StockImages::APP_ICON;

        $response = new Response();
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setStaleWhileRevalidate(self::STALE_SECONDS);
        $response->setEtag($this->etag($logo, $stock, $small));

        if ($response->isNotModified($request)) {
            return $response;
        }

        $variant = null === $logo ? null : $this->variants->of($logo, $small ? Variants::SMALL : Variants::LARGE);

        if (null !== $variant) {
            $response->setContent($this->storage->read($variant));
            $response->headers->set('Content-Type', 'image/png');

            return $response;
        }

        $response->setContent($this->stockImages->contents($stock));
        $response->headers->set('Content-Type', $this->stockImages->contentType($stock));

        return $response;
    }

    #[Route('/account/logo', name: 'account_logo_destroy', methods: ['DELETE'])]
    #[IsGranted(AdministerVoter::CAN_ADMINISTER)]
    #[IsCsrfTokenValid('account_logo_destroy', tokenKey: '_csrf_token')]
    public function destroy(): Response
    {
        $account = $this->account();

        if (null !== $account) {
            $this->attachments->purge($account, Attachments::LOGO);
        }

        return $this->redirectToRoute('account_edit');
    }

    /**
     * The entity tag of the image that is about to be sent.
     *
     * It describes the source of the bytes rather than the account alone, so a
     * client holding a previous logo is not told it is still current. The icon
     * shipped with the application is part of it as well: it is what every
     * installation without a logo serves, and a browser keeps a manifest icon
     * for a week after it goes stale.
     */
    private function etag(?ActiveStorageBlob $logo, string $stock, bool $small): string
    {
        return md5(\sprintf(
            '%s-%s-%s-%d',
            null === $logo ? 'default' : 'logo-'.$logo->getKey(),
            $small ? 'small' : 'large',
            $stock,
            (int) @filemtime($this->stockImages->path($stock)),
        ));
    }

    /**
     * The account of the installation. It is missing until the first run has
     * been completed, which is why every use is guarded.
     */
    private function account(): ?Account
    {
        return $this->accounts->findOneBy([]);
    }
}
