<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ActiveStorageBlob;

/**
 * Covers the pictures a member uploads, walked the way a browser walks them:
 * signing up keeps the picture chosen with the account, the profile page shows
 * the avatar, uploading one replaces the generated picture, and deleting it
 * brings the generated one back.
 */
final class AvatarsTest extends DatabaseTestCase
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

    public function testSigningUpKeepsThePictureChosenWithTheAccount(): void
    {
        $crawler = $this->client->request('GET', '/first_run');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form([
            'registration[name]' => 'Alice',
            'registration[emailAddress]' => 'alice@example.com',
            'registration[password]' => 'correct horse battery',
        ]);
        $form['registration[avatar]']->upload($this->picture(120, 120), 'avatar.png');

        $this->client->submit($form);
        self::assertResponseRedirects('/');

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertInstanceOf(ActiveStorageBlob::class, $blob);
        self::assertSame('avatar.png', $blob->getFilename());

        // The picture replaces the one that would have been drawn from the
        // initials of the name.
        $this->client->request('GET', $this->avatarUrl());

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/webp', $this->contentType());
    }

    public function testTheAvatarOfAMemberWithoutAPictureIsGeneratedFromTheirInitials(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', $this->avatarUrl());

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/svg+xml', $this->contentType());

        // Alice has no picture, so the answer is drawn from her name.
        self::assertMatchesRegularExpression('/>\s*A\s*</', (string) $this->client->getResponse()->getContent());
    }

    public function testUploadingAnAvatarAnswersWithTheResizedPicture(): void
    {
        $this->runFirstRun();

        $this->uploadAvatar(800, 600);

        $this->client->request('GET', $this->avatarUrl());

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/webp', $this->contentType());

        $image = imagecreatefromstring((string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(\GdImage::class, $image);

        // The picture is shrunk to fit a 512 square, keeping its proportions.
        self::assertSame(512, imagesx($image));
        self::assertSame(384, imagesy($image));
    }

    public function testTheStoredBlobDescribesTheUploadedFile(): void
    {
        $this->runFirstRun();

        $this->uploadAvatar(120, 120);

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertInstanceOf(ActiveStorageBlob::class, $blob);

        self::assertSame('avatar.png', $blob->getFilename());
        self::assertSame('image/png', $blob->getContentType());
        self::assertSame('local', $blob->getServiceName());
        self::assertSame(120, $blob->getMetadata()['width'] ?? null);
        self::assertSame(120, $blob->getMetadata()['height'] ?? null);

        // The file is on disk under the key, as ActiveStorage stores it.
        self::assertFileExists($this->filesDir().'/'.$blob->getKey());
        self::assertSame(filesize($this->filesDir().'/'.$blob->getKey()), $blob->getByteSize());
        self::assertSame(base64_encode(md5((string) file_get_contents($this->filesDir().'/'.$blob->getKey()), true)), $blob->getChecksum());
    }

    public function testDeletingTheAvatarBringsTheGeneratedPictureBack(): void
    {
        $this->runFirstRun();

        $this->uploadAvatar(120, 120);

        // The button is posted the way the page posts it, carrying the token
        // the page writes in its form.
        $crawler = $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/avatar"]:has(input[name="_method"])')->form());

        self::assertResponseRedirects('/users/me/profile');

        self::assertNull($this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_attachments'));

        $this->client->request('GET', $this->avatarUrl());
        self::assertStringStartsWith('image/svg+xml', $this->contentType());
    }

    public function testDeletingTheAvatarWithoutItsTokenIsRefused(): void
    {
        $this->runFirstRun();

        $this->uploadAvatar(120, 120);

        $crawler = $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/avatar"]:has(input[name="_method"])')->form();
        $form->setValues(['_csrf_token' => 'not-the-token']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]));
    }

    public function testAnAvatarIsNotReachableWithoutSigningIn(): void
    {
        $this->runFirstRun();
        $url = $this->avatarUrl();
        $this->signOut();

        $this->client->request('GET', $url);

        self::assertResponseRedirects();
    }

    public function testSavingTheProfileWithoutTypingAPasswordKeepsTheCurrentOne(): void
    {
        $this->runFirstRun();

        $crawler = $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save changes')->form([
            'profile[name]' => 'Alicia',
            'profile[emailAddress]' => 'alice@example.com',
            'profile[password]' => '',
            'profile[bio]' => 'Keeper of the fire',
        ]));

        self::assertResponseRedirects('/users/me/profile');

        $alice = $this->findUser('alice@example.com');
        self::assertSame('Alicia', $alice->getName());
        self::assertSame('Keeper of the fire', $alice->getBio());

        // The password still works, so the empty field was ignored.
        $this->signOut();
        $this->signIn('alice@example.com');
    }

    public function testTheLogoFallsBackToTheIconShippedWithTheApplication(): void
    {
        $this->runFirstRun();

        // The sign in page shows the logo before anyone is signed in.
        $this->signOut();

        $this->client->request('GET', '/account/logo');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', $this->contentType());

        $this->client->request('GET', '/account/logo?size=small');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', $this->contentType());
    }

    private function uploadAvatar(int $width, int $height): void
    {
        $crawler = $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save changes')->form([
            'profile[name]' => 'Alice',
            'profile[emailAddress]' => 'alice@example.com',
        ]);
        $form['profile[avatar]']->upload($this->picture($width, $height), 'avatar.png');

        $this->client->submit($form);

        self::assertResponseRedirects('/users/me/profile');
    }

    /**
     * The address of the avatar as the profile page writes it, which also
     * checks that the page points at the avatar route.
     */
    private function avatarUrl(): string
    {
        $crawler = $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();

        $url = $crawler->filter('.avatar__form img[data-upload-preview-target="image"]')->attr('src');
        self::assertNotNull($url);

        return $url;
    }

    /**
     * A picture to upload. It carries the name a browser would send, which the
     * application stores as the filename of the blob.
     */
    private function picture(int $width, int $height): string
    {
        $directory = sys_get_temp_dir().'/campfire-avatar-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->directories[] = $directory;

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 40));

        $path = $directory.'/avatar.png';
        imagepng($image, $path);

        return $path;
    }

    private function contentType(): string
    {
        return (string) $this->client->getResponse()->headers->get('Content-Type');
    }

    private function filesDir(): string
    {
        return (string) static::getContainer()->getParameter('campfire.files_dir');
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
}
