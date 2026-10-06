<?php

declare(strict_types=1);

namespace App\Service;

use App\ActiveStorage\Attachments;
use App\ActiveStorage\BlobStorage;
use App\Entity\Account;
use App\Entity\Enum\UserRole;
use App\Entity\OpenRoom;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the account, its first administrator and its first room, and lets new
 * members join the open rooms that already exist.
 */
final class AccountSetup
{
    public const FIRST_ROOM_NAME = 'All Talk';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly OpenRoomMembership $openRooms,
        private readonly BlobStorage $storage,
        private readonly Attachments $attachments,
    ) {
    }

    /**
     * Creates the account, its first room and the administrator who owns them.
     */
    public function createFirstRun(RegistrationData $data): User
    {
        $account = new Account();
        $account->setName(Account::DEFAULT_NAME);

        $administrator = $this->buildUser($data);
        $administrator->setRole(UserRole::Administrator);

        $room = new OpenRoom();
        $room->setName(self::FIRST_ROOM_NAME);
        $room->setCreator($administrator);

        $this->entityManager->persist($account);
        $this->entityManager->persist($administrator);
        $this->entityManager->persist($room);
        $this->entityManager->flush();

        $membership = $room->addMember($administrator);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        $this->storeAvatar($administrator, $data);

        return $administrator;
    }

    /**
     * Creates a member and adds them to every open room.
     */
    public function createMember(RegistrationData $data): User
    {
        $user = $this->buildUser($data);
        $user->setRole(UserRole::Member);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->openRooms->join($user);
        $this->storeAvatar($user, $data);

        return $user;
    }

    private function buildUser(RegistrationData $data): User
    {
        $user = new User();
        $user->setName($data->name);
        $user->setEmailAddress($data->emailAddress);
        $user->setPasswordDigest($this->passwordHasher->hashPassword($user, $data->password));

        return $user;
    }

    /**
     * Keeps the picture picked while signing up, when one was picked.
     *
     * The person is written first, because the file is linked to them by the
     * identifier they are given.
     */
    private function storeAvatar(User $user, RegistrationData $data): void
    {
        if (null === $data->avatar) {
            return;
        }

        $blob = $this->storage->store(
            (string) file_get_contents($data->avatar->getPathname()),
            $data->avatar->getClientOriginalName(),
            $data->avatar->getMimeType(),
        );

        $this->attachments->replace($blob, $user, Attachments::AVATAR);
    }
}
