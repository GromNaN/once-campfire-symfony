<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActionText\SignedId;
use App\Entity\ActiveStorageBlob;
use App\Entity\OpenRoom;
use App\Entity\User;

/**
 * A signed identifier is a capability: it names a record and the signature is
 * what proves the application issued it. These walk the addresses a browser
 * would follow and check that a hand-forged identifier buys nothing, while a
 * real one still works.
 */
final class SignedIdSecurityTest extends DatabaseTestCase
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

    public function testAForgedTransferTokenDoesNotSignAnyoneIn(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');

        $this->signOut();

        $forged = $this->forge('User', (int) $alice->getId(), SignedId::PURPOSE_TRANSFER);

        // The page cannot even be opened with an identifier nobody signed.
        $this->client->request('GET', '/session/transfers/'.$forged);
        self::assertResponseStatusCodeSame(400);

        // The forged identifier is then sent the way the form would send it,
        // carrying a token the application itself issued, so the only thing left
        // to refuse it is the missing signature.
        $token = $this->transferCsrfToken($this->signedTransfer($alice));

        $this->client->request('PUT', '/session/transfers/'.$forged, ['_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(400);

        // Nobody was signed in: the home page still sends the visitor away.
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/session/new');
    }

    public function testASignedTransferTokenSignsTheUserIn(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');

        $this->signOut();

        $crawler = $this->client->request('GET', '/session/transfers/'.$this->signedTransfer($alice));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form());
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();
    }

    public function testAForgedBlobTokenIsRefused(): void
    {
        $this->runFirstRun();
        $blob = $this->uploadFile();

        // The identifier names a file that really exists, so only the missing
        // signature can be what refuses the read.
        $forged = $this->forge('ActiveStorage::Blob', (int) $blob->getId(), SignedId::PURPOSE_BLOB);

        $this->client->request('GET', '/rails/active_storage/blobs/'.$forged.'/'.$blob->getFilename());
        self::assertResponseStatusCodeSame(404);
    }

    public function testASignedBlobUrlServesTheFile(): void
    {
        $this->runFirstRun();
        $blob = $this->uploadFile();

        $this->client->request('GET', $this->blobUrl($blob));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testATokenWhoseSignatureHoldsTheSeparatorIsStillRead(): void
    {
        $signedIds = static::getContainer()->get(SignedId::class);

        // The signature is base64 as well, so about one token in a hundred
        // carries the same "--" that separates it from the payload. The first
        // separator is the boundary, so such a token must still be read.
        $token = null;
        $id = 0;

        for ($candidate = 1; $candidate <= 5000; ++$candidate) {
            $encoded = $signedIds->encode('User', $candidate, SignedId::PURPOSE_TRANSFER);
            $signature = substr($encoded, strpos($encoded, '--') + 2);

            if (str_contains($signature, '--')) {
                $token = $encoded;
                $id = $candidate;
                break;
            }
        }

        self::assertNotNull($token, 'No signed id with "--" in its signature was found.');
        self::assertSame(
            ['model' => 'User', 'id' => (string) $id, 'purpose' => SignedId::PURPOSE_TRANSFER],
            $signedIds->decode($token),
        );
    }

    public function testAPayloadHoldingTheSeparatorIsStillRead(): void
    {
        $signedIds = static::getContainer()->get(SignedId::class);
        $secret = (string) static::getContainer()->getParameter('kernel.secret');

        // The payload and the signature both use the URL safe base64 alphabet,
        // where "--" can appear, so the separator is not unique to the
        // boundary. The model name below is picked so the encoded payload
        // carries a separator of its own: a split on the first "--" would cut
        // the payload in half, while anchoring on the fixed length of the
        // signature reads it whole.
        $gid = "gid://campfire/User\u{103FE}/1";
        $payload = json_encode(
            ['_rails' => ['data' => $gid, 'exp' => null, 'pur' => SignedId::PURPOSE_TRANSFER]],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        $message = strtr(base64_encode($payload), '+/', '-_');

        self::assertStringContainsString('--', $message);

        $signature = strtr(base64_encode(hash_hmac('sha256', $message, $secret, true)), '+/', '-_');

        self::assertSame(
            ['model' => "User\u{103FE}", 'id' => '1', 'purpose' => SignedId::PURPOSE_TRANSFER],
            $signedIds->decode($message.'--'.$signature),
        );
    }

    private function signedTransfer(User $user): string
    {
        return static::getContainer()->get(SignedId::class)->encode(
            'User',
            (int) $user->getId(),
            SignedId::PURPOSE_TRANSFER,
        );
    }

    /**
     * A valid token for the transfer form, taken from the page that carries it.
     * It is paired with a forged identifier so that the request passes the CSRF
     * check and reaches the signature check.
     */
    private function transferCsrfToken(string $signedId): string
    {
        $crawler = $this->client->request('GET', '/session/transfers/'.$signedId);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form input[name="_csrf_token"]')->attr('value');
    }

    private function blobUrl(ActiveStorageBlob $blob): string
    {
        $sgid = static::getContainer()->get(SignedId::class)->encode(
            'ActiveStorage::Blob',
            (int) $blob->getId(),
            SignedId::PURPOSE_BLOB,
        );

        return '/rails/active_storage/blobs/'.$sgid.'/'.$blob->getFilename();
    }

    /**
     * A payload built by hand, with no signature: exactly what anyone can write
     * without knowing the secret.
     */
    private function forge(string $model, int $id, string $purpose): string
    {
        $payload = json_encode([
            '_rails' => [
                'data' => \sprintf('gid://campfire/%s/%d', $model, $id),
                'exp' => null,
                'pur' => $purpose,
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return strtr(base64_encode($payload), '+/', '-_');
    }

    private function uploadFile(): ActiveStorageBlob
    {
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $form = $crawler->selectButton('Send')->form();
        $form['message[attachment]']->upload($this->picture());

        $this->client->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();

        $blob = $this->entityManager()->getRepository(ActiveStorageBlob::class)->findOneBy([]);
        self::assertInstanceOf(ActiveStorageBlob::class, $blob);

        return $blob;
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    /**
     * A picture to upload, named the way a browser names one.
     */
    private function picture(): string
    {
        $directory = sys_get_temp_dir().'/campfire-signed-id-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700, true));
        $this->directories[] = $directory;

        $image = imagecreatetruecolor(40, 40);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));

        $path = $directory.'/picture.png';
        imagepng($image, $path);

        return $path;
    }
}
