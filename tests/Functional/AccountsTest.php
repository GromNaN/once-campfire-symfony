<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActiveStorage\StockImages;
use App\Entity\Account;
use App\Entity\ActiveStorageBlob;
use App\Entity\ClosedRoom;
use App\Entity\DirectRoom;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\Membership;
use App\Entity\PushSubscription;
use App\Entity\Search;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Service\AccountSetup;

/**
 * Covers the account page: its name and picture, the join link, the switches,
 * and the members an administrator manages from there.
 */
final class AccountsTest extends DatabaseTestCase
{
    /**
     * Directories holding the pictures built by the test.
     *
     * @var list<string>
     */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        $this->directories = [];

        parent::tearDown();
    }

    public function testTheAccountPageShowsTheNameAndTheJoinLink(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="account[name]"][value="Campfire"]');

        $account = $this->account();
        self::assertSelectorExists('input[value$="/join/'.$account->getJoinCode().'"]');
    }

    public function testAnAdministratorRenamesTheAccount(): void
    {
        $this->runFirstRun();

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="account"]')->form([
            'account[name]' => 'Basecamp',
        ]));

        self::assertResponseRedirects('/account');
        self::assertSame('Basecamp', $this->account()->getName());

        // The new name is what every page shows.
        $this->client->request('GET', '/account');
        self::assertSelectorExists('input[name="account[name]"][value="Basecamp"]');
    }

    public function testAnAdministratorUploadsALogo(): void
    {
        $this->runFirstRun();

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="account"]')->form(['account[name]' => 'Campfire']);
        $form['account[logo]']->upload($this->picture(120, 120), 'logo.png');

        $this->client->submit($form);

        self::assertResponseRedirects('/account');

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertNotNull($blob);
        self::assertSame('logo.png', $blob->getFilename());

        // The page now serves the uploaded picture rather than the shipped icon.
        $this->client->request('GET', '/account/logo?size=small');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNotSame(
            static::getContainer()->get(StockImages::class)->contents(StockImages::APP_ICON_SMALL),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testAnAdministratorDeletesTheLogo(): void
    {
        $this->runFirstRun();
        $this->uploadLogo();

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[action$="/account/logo"]')->form());

        self::assertResponseRedirects('/account');
        self::assertCount(0, $this->entityManager()->getRepository(ActiveStorageBlob::class)->findAll());

        $this->client->request('GET', '/account/logo');
        self::assertStringStartsWith('image/png', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testTheLogoStaysAPngForAClientThatAcceptsSvg(): void
    {
        $this->runFirstRun();

        // What a browser sends when it fetches a picture. It names the SVG type
        // among the ones it accepts, which is not a reason to answer an SVG.
        $this->client->request('GET', '/account/logo?size=small', server: [
            'HTTP_ACCEPT' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith("\x89PNG", (string) $this->client->getResponse()->getContent());
    }

    public function testTheEntityTagOfTheLogoFollowsTheImage(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/account/logo?size=small');
        self::assertResponseIsSuccessful();
        $shipped = (string) $this->client->getResponse()->headers->get('ETag');

        $this->uploadLogo();

        $this->client->request('GET', '/account/logo?size=small');
        self::assertResponseIsSuccessful();
        self::assertNotSame($shipped, (string) $this->client->getResponse()->headers->get('ETag'));

        // A browser that still holds the shipped icon asks whether it may keep
        // it. The tag has moved on, so the answer is the picture, not a 304.
        $this->client->request('GET', '/account/logo?size=small', server: ['HTTP_IF_NONE_MATCH' => $shipped]);

        self::assertResponseIsSuccessful();
        self::assertNotSame(
            static::getContainer()->get(StockImages::class)->contents(StockImages::APP_ICON_SMALL),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testRegeneratingTheJoinCodeRetiresTheOldLink(): void
    {
        $this->runFirstRun();
        $oldCode = $this->account()->getJoinCode();

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/account/join_code"]')->form());

        self::assertResponseRedirects('/account');

        $newCode = $this->account()->getJoinCode();
        self::assertNotSame($oldCode, $newCode);

        // The link that was already handed out stops working.
        $this->signOut();

        $this->client->request('GET', '/join/'.$oldCode);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/join/'.$newCode);
        self::assertResponseIsSuccessful();
    }

    public function testTheRoomCreationSwitchKeepsMembersFromOpeningRooms(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="account_settings"]')->form();
        $form['account_settings[restrictRoomCreationToAdministrators]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/account');
        self::assertTrue($this->account()->restrictRoomCreationToAdministrators());

        $this->signOut();
        $this->signIn('bob@example.com');

        // The sidebar no longer offers the link, and the address is refused too.
        $this->client->request('GET', '/users/me/sidebar');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('a[href="/rooms/opens/new"]'));

        $this->client->request('GET', '/rooms/opens/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdministratorWritesCustomStylesThatEveryPageServes(): void
    {
        $this->runFirstRun();

        $crawler = $this->client->request('GET', '/account/custom_styles/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="custom_styles"]')->form([
            'custom_styles[customStyles]' => '.sidebar { background: #123456; }',
        ]));

        self::assertResponseRedirects('/account/custom_styles/edit');
        self::assertSame('.sidebar { background: #123456; }', $this->account()->getCustomStyles());

        $this->client->request('GET', '/account');
        self::assertStringContainsString('.sidebar { background: #123456; }', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdministratorPromotesAMember(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/account/users/'.$bob->getId().'"]:has(input[type="checkbox"])')->form();
        $form['account_user_role[administrator]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/account');

        $bob = $this->findUser('bob@example.com');
        self::assertSame(UserRole::Administrator, $bob->getRole());
    }

    public function testAnAdministratorDemotesAnotherAdministrator(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $bob->setRole(UserRole::Administrator);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/account/users/'.$bob->getId().'"]:has(input[type="checkbox"])')->form();
        $form['account_user_role[administrator]']->untick();
        $this->client->submit($form);

        self::assertResponseRedirects('/account');
        self::assertSame(UserRole::Member, $this->findUser('bob@example.com')->getRole());
    }

    public function testAMemberSeesThePageWithoutItsControls(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');
        $this->signOut();
        $this->signIn('bob@example.com');

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('form[name="account"]'));
        self::assertCount(0, $this->client->getCrawler()->filter('form[action^="/account/users/"]:has(input[type="checkbox"])'));
    }

    public function testAMemberCannotRenameTheAccount(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');
        $this->signOut();
        $this->signIn('bob@example.com');

        $crawler = $this->client->request('GET', '/account');
        self::assertCount(0, $crawler->filter('form[name="account"]'));

        // The controls are gone from the page, so the request is built by hand.
        $this->client->request('PATCH', '/account', ['account' => ['name' => 'Mine']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Campfire', $this->account()->getName());
    }

    public function testAMemberCannotOpenTheBotPages(): void
    {
        $this->runFirstRun();
        $this->addMember('Bob', 'bob@example.com');
        $this->signOut();
        $this->signIn('bob@example.com');

        $this->client->request('GET', '/account/bots');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/account/custom_styles/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdministratorDeactivatesAMember(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');
        $bobId = (int) $bob->getId();
        $alice = $this->findUser('alice@example.com');

        // Bob is in a shared room and in a direct conversation, has a browser
        // registered, a past search and a session.
        $shared = $this->closedRoom('Secret', $alice, $bob);
        $this->directRoom($alice, $bob);
        $this->subscribe($bob);
        $this->entityManager()->persist((new Search())->setUser($bob)->setQuery('deploy'));
        $this->entityManager()->flush();

        $this->signOut();
        $this->signIn('bob@example.com');
        $this->client->request('GET', '/users/me/sidebar');
        self::assertResponseIsSuccessful();

        $this->signOut();
        $this->signIn('alice@example.com');

        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[action$="/account/users/'.$bobId.'"]:has(input[name="_csrf_token"])')->form());

        self::assertResponseRedirects('/account');

        $bob = $this->findUserById($bobId);
        self::assertSame(UserStatus::Deactivated, $bob->getStatus());

        // The address is rewritten, so the same person can join again later.
        self::assertStringStartsWith('bob-deactivated-', (string) $bob->getEmailAddress());
        self::assertStringEndsWith('@example.com', (string) $bob->getEmailAddress());

        // The shared room is left, the direct conversation is kept, and the
        // browser, the search and the session are gone.
        self::assertCount(0, $this->membershipsOf($bobId, $shared));
        self::assertCount(1, $this->membershipsOf($bobId, null, DirectRoom::class));
        self::assertCount(0, $this->entityManager()->getRepository(PushSubscription::class)->findAll());
        self::assertCount(0, $this->entityManager()->getRepository(Search::class)->findAll());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$bobId]));

        // The account page no longer lists them.
        $this->client->request('GET', '/account');
        self::assertStringNotContainsString('Bob', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdministratorCannotRemoveThemselves(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');
        $bob = $this->addMember('Bob', 'bob@example.com');

        // The page hands out a token that is valid for removing a member; it
        // is used against the administrator themselves, which the server has
        // to refuse on its own.
        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/account/users/'.$bob->getId().'"]:has(input[name="_csrf_token"]) input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('DELETE', '/account/users/'.$alice->getId(), [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(UserStatus::Active, $this->findUser('alice@example.com')->getStatus());
    }

    public function testDeactivatingAMemberWithoutTheTokenIsRefused(): void
    {
        $this->runFirstRun();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $this->client->request('DELETE', '/account/users/'.$bob->getId(), [
            '_csrf_token' => 'not-the-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(UserStatus::Active, $this->findUser('bob@example.com')->getStatus());
    }

    private function account(): Account
    {
        $account = $this->entityManager()->getRepository(Account::class)->findOneBy([]);
        self::assertNotNull($account);

        return $account;
    }

    private function uploadLogo(): void
    {
        $crawler = $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="account"]')->form(['account[name]' => 'Campfire']);
        $form['account[logo]']->upload($this->picture(120, 120), 'logo.png');

        $this->client->submit($form);
        self::assertResponseRedirects('/account');
    }

    private function addMember(string $name, string $emailAddress): User
    {
        $data = new RegistrationData();
        $data->name = $name;
        $data->emailAddress = $emailAddress;
        $data->password = 'correct horse battery';

        static::getContainer()->get(AccountSetup::class)->createMember($data);

        return $this->findUser($emailAddress);
    }

    private function findUserById(?int $id): User
    {
        $user = $this->entityManager()->getRepository(User::class)->find($id);
        self::assertNotNull($user);

        return $user;
    }

    /**
     * @return list<Membership>
     */
    private function membershipsOf(int $userId, ?ClosedRoom $room = null, ?string $roomType = null): array
    {
        $builder = $this->entityManager()->createQueryBuilder()
            ->select('membership')
            ->from(Membership::class, 'membership')
            ->andWhere('membership.user = :user')
            ->setParameter('user', $userId);

        if (null !== $room) {
            $builder->andWhere('membership.room = :room')->setParameter('room', $room);
        }

        if (null !== $roomType) {
            $builder->andWhere('membership.room IN (SELECT r.id FROM '.$roomType.' r)');
        }

        return $builder->getQuery()->getResult();
    }

    private function closedRoom(string $name, User $creator, User $member): ClosedRoom
    {
        $room = new ClosedRoom();
        $room->setName($name);
        $room->setCreator($creator);

        $this->entityManager()->persist($room);
        $this->entityManager()->persist($room->addMember($creator));
        $this->entityManager()->persist($room->addMember($member));
        $this->entityManager()->flush();

        return $room;
    }

    private function directRoom(User $first, User $second): DirectRoom
    {
        $room = new DirectRoom();
        $room->setCreator($first);

        $this->entityManager()->persist($room);
        $this->entityManager()->persist($room->addMember($first));
        $this->entityManager()->persist($room->addMember($second));
        $this->entityManager()->flush();

        return $room;
    }

    private function subscribe(User $user): void
    {
        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setEndpoint('https://fcm.googleapis.com/fcm/send/abc123');
        $subscription->setP256dhKey('BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM');
        $subscription->setAuthKey('tBHItJI5svbpez7KI4CCXg');

        $this->entityManager()->persist($subscription);
        $this->entityManager()->flush();
    }

    private function signIn(string $emailAddress): void
    {
        $crawler = $this->client->request('GET', '/session/new');
        $this->client->submit($crawler->selectButton('Go')->form([
            'login[emailAddress]' => $emailAddress,
            'login[password]' => 'correct horse battery',
        ]));

        self::assertResponseRedirects('/');
    }

    /**
     * A picture to upload, carrying the name a browser would send.
     */
    private function picture(int $width, int $height): string
    {
        $directory = sys_get_temp_dir().'/campfire-account-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->directories[] = $directory;

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 160));

        $path = $directory.'/logo.png';
        imagepng($image, $path);

        return $path;
    }
}
