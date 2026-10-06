<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ActionText\SignedId;
use App\Entity\Enum\MembershipInvolvement;
use App\Entity\Membership;
use App\Entity\OpenRoom;
use App\Entity\Room;
use App\Entity\User;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The forms that are written by hand rather than built with the Form component
 * carry their own token. This walks them the way a browser walks them: the page
 * writes the token, the post sends it back, and a post without it is refused.
 */
final class CsrfProtectionTest extends DatabaseTestCase
{
    public function testDeletingARoomWithoutItsTokenIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/opens/'.$room->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/rooms/'.$room->getId().'"]:has(input[name="_csrf_token"])')->form();
        $form->setValues(['_csrf_token' => 'not-the-token']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager()->getRepository(Room::class)->find($room->getId()));
    }

    public function testDeletingARoomWithItsTokenRemovesIt(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/opens/'.$room->getId().'/edit');
        self::assertResponseIsSuccessful();

        // The page carries the token, so posting the form as it is written is
        // enough: nothing here knows what the token looks like.
        $this->client->submit($crawler->filter('form[action$="/rooms/'.$room->getId().'"]:has(input[name="_csrf_token"])')->form());

        self::assertResponseRedirects('/');
        self::assertNull($this->entityManager()->getRepository(Room::class)->find($room->getId()));
    }

    public function testChangingHowMuchYouHearAboutARoomWithoutItsTokenIsRefused(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $before = $this->membership($room)->getInvolvement();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId().'/involvement');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $asked = (string) $form->getValues()['involvement'];
        self::assertNotSame($before->value, $asked);
        $form->setValues(['_csrf_token' => 'not-the-token']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->membership($room)->getInvolvement());
    }

    public function testChangingHowMuchYouHearAboutARoomWithItsTokenWorks(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $crawler = $this->client->request('GET', '/rooms/'.$room->getId().'/involvement');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $asked = MembershipInvolvement::from((string) $form->getValues()['involvement']);

        $this->client->submit($form);

        self::assertResponseRedirects('/rooms/'.$room->getId());
        self::assertSame($asked, $this->membership($room)->getInvolvement());
    }

    public function testATransferLinkWithoutItsTokenDoesNotSignAnyoneIn(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');

        $this->signOut();

        $id = static::getContainer()->get(SignedId::class)->encode(
            'User',
            (int) $alice->getId(),
            SignedId::PURPOSE_TRANSFER,
        );

        // The page posts its own form as soon as it is opened, so a reader only
        // has to follow the link.
        $this->client->request('GET', '/session/transfers/'.$id);
        self::assertResponseIsSuccessful();

        $this->client->request('PUT', '/session/transfers/'.$id, ['_csrf_token' => 'not-the-token']);

        // The reader was not signed in, so the refusal takes them to the sign
        // in page rather than to the page the link would have opened.
        self::assertResponseRedirects('/session/new');

        // Nobody was signed in by the refused request: the home page still
        // sends the visitor to the sign in page.
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/session/new');
    }

    public function testATransferLinkWithItsTokenSignsTheUserIn(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');

        $this->signOut();

        $id = static::getContainer()->get(SignedId::class)->encode(
            'User',
            (int) $alice->getId(),
            SignedId::PURPOSE_TRANSFER,
        );

        $crawler = $this->client->request('GET', '/session/transfers/'.$id);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form());

        self::assertResponseRedirects('/');

        $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();
    }

    public function testATransferLinkWorksForAReaderWhoSignedInWithADoubleSubmitToken(): void
    {
        $this->runFirstRun();
        $alice = $this->findUser('alice@example.com');

        $this->signOut();

        // A browser writes a double-submit token on the sign in page and sends
        // it back with the cookie its script wrote. The stateless protection
        // then remembers that both the origin and the double-submit were
        // usable, and refuses a later request carrying only one of them.
        $token = str_repeat('A', 24);
        $this->client->getCookieJar()->set(new Cookie('csrf-token_'.$token, 'csrf-token', null, '/', 'localhost'));

        $crawler = $this->client->request('GET', '/session/new');
        $this->client->submit($crawler->selectButton('Go')->form([
            'login[emailAddress]' => 'alice@example.com',
            'login[password]' => 'correct horse battery',
            'login[_token]' => $token,
        ]));
        self::assertResponseRedirects('/');

        $id = static::getContainer()->get(SignedId::class)->encode(
            'User',
            (int) $alice->getId(),
            SignedId::PURPOSE_TRANSFER,
        );

        // The transfer page sends its own form as it loads, so the request it
        // makes carries the origin and nothing else.
        $crawler = $this->client->request('GET', '/session/transfers/'.$id);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form')->form());

        self::assertResponseRedirects('/');

        $this->client->request('GET', '/users/me/profile');
        self::assertResponseIsSuccessful();
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    private function membership(Room $room): Membership
    {
        $membership = $this->entityManager()->getRepository(Membership::class)->findOneBy([
            'room' => $room,
            'user' => $this->currentUser(),
        ]);
        self::assertNotNull($membership);

        return $membership;
    }

    private function currentUser(): User
    {
        return $this->findUser('alice@example.com');
    }
}
