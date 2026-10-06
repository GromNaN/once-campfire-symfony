<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\Entity\ActiveStorageBlob;
use App\Entity\User;
use App\Form\Data\ProfileData;
use App\Form\ProfileType;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The page where a member changes their own name, picture and credentials.
 *
 * Only the signed in user can be edited, so the address carries no identifier
 * at all. A field left empty is not applied, which is how the original behaves:
 * saving the page without typing a password keeps the current one.
 */
final class ProfilesController extends AbstractController
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly Attachments $attachments,
        private readonly BlobStorage $storage,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/users/me/profile', name: 'user_profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): Response
    {
        $data = new ProfileData();
        $data->name = $user->getName();
        $data->emailAddress = $user->getEmailAddress();
        $data->bio = $user->getBio();

        return $this->page($user, $this->createForm(ProfileType::class, $data));
    }

    #[Route('/users/me/profile', name: 'user_profile_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ProfileType::class, $data = new ProfileData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->apply($user, $data);

            return $this->redirectToRoute('user_profile_show');
        }

        return $this->page($user, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function apply(User $user, ProfileData $data): void
    {
        $user->setName((string) $data->name);
        $user->setEmailAddress($this->blankToNull($data->emailAddress));
        $user->setBio($this->blankToNull($data->bio));

        if (null !== $data->password && '' !== $data->password) {
            $user->setPasswordDigest($this->passwordHasher->hashPassword($user, $data->password));
        }

        $this->entityManager->flush();

        if (null !== $data->avatar) {
            $this->attachments->replace($this->storeAvatar($data->avatar), $user, Attachments::AVATAR);
        }
    }

    private function storeAvatar(UploadedFile $avatar): ActiveStorageBlob
    {
        $contents = (string) file_get_contents($avatar->getPathname());

        return $this->storage->store($contents, $avatar->getClientOriginalName(), $avatar->getMimeType());
    }

    private function page(User $user, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        $direct = [];
        $shared = [];

        foreach ($this->memberships->findAllForUserOrdered($user) as $membership) {
            if ($membership->getRoom()?->isDirect()) {
                $direct[] = $membership;
            } else {
                $shared[] = $membership;
            }
        }

        return $this->render(
            'users/profiles/show.html.twig',
            [
                'user' => $user,
                'form' => $form,
                'direct_memberships' => $direct,
                'shared_memberships' => $shared,
                'has_avatar' => null !== $this->attachments->blobFor($user, Attachments::AVATAR),
            ],
            new Response('', $status),
        );
    }

    private function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : $value;
    }
}
