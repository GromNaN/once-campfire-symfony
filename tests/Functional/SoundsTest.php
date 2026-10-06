<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Message;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Sound\SoundLibrary;

/**
 * Covers the sounds a message plays.
 *
 * A message whose whole text is "/play <name>" is shown as the sound rather
 * than the text, and the name has to be one of the sounds the application
 * ships with. Everything else stays the text it was written as.
 */
final class SoundsTest extends DatabaseTestCase
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

    public function testAMessageAskingForASoundShowsTheSound(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postMessage($room, '/play bell');

        self::assertSelectorExists('#message_'.$message->getKey().' .sound');
        // The asset mapper names the file with a digest of its contents, so
        // the address is matched on the part that does not move.
        self::assertSelectorExists('#message_'.$message->getKey().' .sound[data-sound-url-value*="sounds/bell"]');

        // The text is replaced by the sound, so the request itself is not shown.
        self::assertStringNotContainsString('/play bell', (string) $this->client->getResponse()->getContent());
    }

    public function testASoundWithAPictureShowsThePicture(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postMessage($room, '/play 56k');

        self::assertSelectorExists('#message_'.$message->getKey().' .sound img[src*="sounds/56k"]');
    }

    public function testANameThatIsNotASoundStaysText(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postMessage($room, '/play not-a-sound');

        self::assertSelectorExists('#message_'.$message->getKey());
        self::assertCount(0, $this->client->getCrawler()->filter('#message_'.$message->getKey().' .sound'));
        self::assertSelectorTextContains('#message_'.$message->getKey(), '/play not-a-sound');
    }

    public function testASoundNameIsOnlyARequestWhenItIsTheWholeMessage(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $message = $this->postMessage($room, 'let us /play bell later');

        self::assertCount(0, $this->client->getCrawler()->filter('#message_'.$message->getKey().' .sound'));
    }

    public function testAFileWinsOverASound(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        // A message carries at most one file, and the file is what is shown.
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $form = $crawler->selectButton('Send')->form(['message[body]' => '/play bell']);
        $form['message[attachment]']->upload($this->picture());

        $this->client->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();

        $message = $this->lastMessage($room);
        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertSelectorExists('#message_'.$message->getKey().' img.message__attachment');
        self::assertCount(0, $this->client->getCrawler()->filter('#message_'.$message->getKey().' .sound'));
    }

    public function testTheSoundLibraryListsItsNamesSorted(): void
    {
        $library = static::getContainer()->get(SoundLibrary::class);
        $names = $library->names();

        self::assertContains('bell', $names);
        self::assertContains('trombone', $names);

        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);

        // Every name resolves, and the audio file of each one is shipped.
        foreach ($names as $name) {
            $sound = $library->find($name);
            self::assertNotNull($sound);
            self::assertFileExists(__DIR__.'/../../assets/'.$sound->audioPath());
        }
    }

    public function testAnUnknownSoundNameIsNotFound(): void
    {
        self::assertNull(static::getContainer()->get(SoundLibrary::class)->find('nope'));
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
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

    /**
     * A picture to upload, named the way a browser names one.
     */
    private function picture(): string
    {
        $directory = sys_get_temp_dir().'/campfire-sound-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->directories[] = $directory;

        $image = imagecreatetruecolor(80, 80);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));

        $path = $directory.'/picture.png';
        imagepng($image, $path);

        return $path;
    }

    /**
     * Posts a message through the composer, then reads it back so the test can
     * address the stored row.
     */
    private function postMessage(Room $room, string $body): Message
    {
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => $body,
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        $message = $this->lastMessage($room);

        // The Turbo Stream answer carries the message, so the page is asked for
        // it again to read the rendering the test asserts on.
        $this->client->request('GET', '/rooms/'.$room->getId());

        return $message;
    }
}
