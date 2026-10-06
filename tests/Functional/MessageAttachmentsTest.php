<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActionText\SignedId;
use App\Entity\ActiveStorageBlob;
use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use App\Form\Data\RegistrationData;
use App\Service\AccountSetup;

/**
 * Covers what a message can carry besides its text: a mention, a link preview
 * and an uploaded file, walked the way a browser walks them.
 */
final class MessageAttachmentsTest extends DatabaseTestCase
{
    private const MENTION_CONTENT_TYPE = 'application/vnd.campfire.mention';
    private const EMBED_CONTENT_TYPE = 'application/vnd.actiontext.opengraph-embed';

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

    public function testAMentionIsShownAsTheNameOfTheUserItPointsAt(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $message = $this->postMessage($room, '<p>Hello '.$this->mention($bob).'</p>');

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message_'.$message->getKey().' .mention');
        self::assertSelectorTextContains('#message_'.$message->getKey().' .mention', 'Bob');

        // The mention carries the picture of the user it points at.
        self::assertStringContainsString(
            '/users/',
            (string) $this->client->getCrawler()->filter('#message_'.$message->getKey().' .mention img')->attr('src'),
        );
    }

    public function testAMentionOfADeletedUserLeavesNothingBehind(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $bob = $this->addMember('Bob', 'bob@example.com');

        $message = $this->postMessage($room, '<p>Hello '.$this->mention($bob).'</p>');

        $entityManager = $this->entityManager();

        // The user is read again: a request resets the entity manager, so the
        // one the setup returned is no longer attached to it.
        $entityManager->remove($entityManager->getRepository(User::class)->find($bob->getId()));
        $entityManager->flush();

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#message_'.$message->getKey(), 'Hello');
        self::assertSelectorNotExists('#message_'.$message->getKey().' .mention');
    }

    public function testALinkPreviewOfThisApplicationIsShownWithoutItsLink(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postMessage($room, '<p>Look '.$this->embed(
            'A page of this application',
            'http://localhost/rooms/1',
            'http://localhost/account/logo',
        ).'</p>');

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // The title stays readable, but nothing in the page points back at this
        // application, which would make every reader fetch it with their
        // session attached.
        self::assertStringContainsString('A page of this application', $html);
        self::assertStringNotContainsString('href="http://localhost/rooms/1"', $html);
        self::assertStringNotContainsString('src="http://localhost/account/logo"', $html);
    }

    public function testALinkPreviewOfAnotherSiteKeepsItsLink(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postMessage($room, '<p>Look '.$this->embed(
            'An article worth reading',
            'https://example.com/article',
            'https://example.com/picture.png',
        ).'</p>');

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('href="https://example.com/article"', $html);
        self::assertStringContainsString('src="https://example.com/picture.png"', $html);
    }

    public function testUploadingAFileMakesAMessageThatShowsIt(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postFile($room, $this->picture(80, 60));

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertInstanceOf(ActiveStorageBlob::class, $blob);
        self::assertSame('picture.png', $blob->getFilename());
        self::assertSame('image/png', $blob->getContentType());

        $attachment = $this->connection()->fetchAssociative('SELECT * FROM active_storage_attachments');
        self::assertIsArray($attachment);
        self::assertSame('Message', $attachment['record_type']);
        self::assertSame($message->getId(), (int) $attachment['record_id']);
        self::assertSame('attachment', $attachment['name']);

        $this->client->request('GET', '/rooms/'.$room->getId().'/messages/'.$message->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#message_'.$message->getKey().' img.message__attachment');

        // The picture is shown through a thumbnail, made when the page asks for
        // it and kept afterwards.
        $source = (string) $this->client->getCrawler()->filter('#message_'.$message->getKey().' img.message__attachment')->attr('src');
        self::assertStringContainsString('/rails/active_storage/blobs/', $source);
        self::assertStringContainsString('variant=thumb', $source);

        $this->client->request('GET', $source);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/webp', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $image = imagecreatefromstring((string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(\GdImage::class, $image);

        // The picture is smaller than the thumbnail box, so it keeps its size.
        self::assertSame(80, imagesx($image));
        self::assertSame(60, imagesy($image));

        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_blobs'));
    }

    public function testAFileCanBeDownloadedUnderItsOwnName(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->postFile($room, $this->picture(40, 40));

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertInstanceOf(ActiveStorageBlob::class, $blob);

        $this->client->request('GET', $this->blobUrl($blob), ['disposition' => 'attachment']);

        self::assertResponseIsSuccessful();

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('picture.png', $disposition);
    }

    public function testAFileIsNotReadableWithoutItsIdentifier(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->postFile($room, $this->picture(40, 40));

        $this->client->request('GET', '/rails/active_storage/blobs/not-a-token/picture.png');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingAMessageRemovesItsFile(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postFile($room, $this->picture(40, 40));

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertInstanceOf(ActiveStorageBlob::class, $blob);
        $path = $this->filesDir().'/'.$blob->getKey();
        self::assertFileExists($path);

        $this->client->request('DELETE', '/rooms/'.$room->getId().'/messages/'.$message->getId(), [], [], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_blobs'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM active_storage_attachments'));
        self::assertFileDoesNotExist($path);
    }

    /**
     * The attachment element a body keeps for a mention.
     */
    private function mention(User $user): string
    {
        $sgid = static::getContainer()->get(SignedId::class)->encode('User', (int) $user->getId(), SignedId::PURPOSE_ATTACHABLE);

        return \sprintf('<action-text-attachment sgid="%s" content-type="%s"></action-text-attachment>', $sgid, self::MENTION_CONTENT_TYPE);
    }

    /**
     * The attachment element a body keeps for a link preview, in the form the
     * older editor wrote it, where the details sit on the element.
     */
    private function embed(string $title, string $href, string $image): string
    {
        return \sprintf(
            '<action-text-attachment content-type="%s" href="%s" url="%s" filename="%s" caption="A caption"></action-text-attachment>',
            self::EMBED_CONTENT_TYPE,
            $href,
            $image,
            $title,
        );
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    private function postMessage(Room $room, string $body): Message
    {
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => $body,
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        return $this->lastMessage($room);
    }

    private function postFile(Room $room, string $path): Message
    {
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $form = $crawler->selectButton('Send')->form();
        $form['message[attachment]']->upload($path);

        $this->client->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        return $this->lastMessage($room);
    }

    private function lastMessage(Room $room): Message
    {
        $message = $this->entityManager()->getRepository(Message::class)->findOneBy(
            ['room' => $room],
            ['id' => 'DESC'],
        );
        self::assertNotNull($message);

        return $message;
    }

    private function blobUrl(ActiveStorageBlob $blob): string
    {
        $sgid = static::getContainer()->get(SignedId::class)->encode('ActiveStorage::Blob', (int) $blob->getId(), SignedId::PURPOSE_BLOB);

        return '/rails/active_storage/blobs/'.$sgid.'/'.$blob->getFilename();
    }

    /**
     * A picture to upload, named the way a browser names one.
     */
    private function picture(int $width, int $height): string
    {
        $directory = sys_get_temp_dir().'/campfire-message-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->directories[] = $directory;

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));

        $path = $directory.'/picture.png';
        imagepng($image, $path);

        return $path;
    }

    private function filesDir(): string
    {
        return (string) static::getContainer()->getParameter('campfire.files_dir');
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
}
