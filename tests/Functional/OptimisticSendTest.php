<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Message;
use App\Entity\OpenRoom;

/**
 * Covers what the browser is given to show a message before it is saved.
 *
 * The browser builds that message from a template the page carries, fills it
 * with an identifier it picks, and hands the same identifier to the form, so
 * that the saved message takes the place of the one shown. None of that can run
 * here, so the test reads the page the way the browser does: the template and
 * its placeholders, the wiring of the list, and the composer that talks to it.
 */
final class OptimisticSendTest extends DatabaseTestCase
{
    public function testTheRoomCarriesTheTemplateOfAMessageNotYetSaved(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();

        self::assertSelectorExists('#message-area script[type="text/template"][data-messages-target="template"]');

        $template = $this->client->getCrawler()
            ->filter('#message-area script[data-messages-target="template"]')
            ->html();

        // The browser replaces each of these with what it knows.
        foreach (['$clientMessageId$', '$body$', '$messageClasses$', '$messageTimestamp$', '$messageDatetime$'] as $placeholder) {
            self::assertStringContainsString($placeholder, $template);
        }

        // The identifier the browser picks is the one the message is known by,
        // which is what lets the saved message replace it.
        self::assertStringContainsString('id="message_$clientMessageId$"', $template);
    }

    public function testTheMessageListIsWiredToShowAndPageThroughMessages(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $user = $this->findUser('alice@example.com');

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();

        $area = $this->client->getCrawler()->filter('#message-area');

        self::assertSame('messages presence drop-target', $area->attr('data-controller'));
        self::assertSame('/rooms/'.$room->getId().'/messages', $area->attr('data-messages-page-url-value'));
        self::assertSame((string) $user->getId(), $area->attr('data-messages-user-id-value'));
        self::assertSame('message--me', $area->attr('data-messages-me-class'));
        self::assertSame('message--mentioned', $area->attr('data-messages-mentioned-class'));
        self::assertSame('message--threaded', $area->attr('data-messages-threaded-class'));
        self::assertSame('message--first-of-day', $area->attr('data-messages-first-of-day-class'));
        self::assertSame('message--formatted', $area->attr('data-messages-formatted-class'));

        // The stream that carries a new message is watched, and the button that
        // goes back to the newest message is known to the controller.
        self::assertStringContainsString('messages#beforeStreamRender', (string) $area->attr('data-action'));
        self::assertStringContainsString('messages#editMyLastMessage', (string) $area->attr('data-action'));
        self::assertSelectorExists('#message-area [data-messages-target="latest"]');

        // The list itself is where a message is added, and it is what the
        // controller scrolls and pages through.
        self::assertSelectorExists('#messages_'.$room->getId().'[data-messages-target="messages"]');
    }

    public function testTheComposerHandsTheMessageToTheListAndKeepsADraft(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();

        $form = $this->client->getCrawler()->filter('form#composer');

        self::assertSame('#message-area', $form->attr('data-composer-messages-outlet'));
        self::assertStringContainsString('composer#submitEnd', (string) $form->attr('data-action'));
        self::assertSame((string) $room->getId(), $form->attr('data-composer-room-id-value'));

        // Sending is intercepted on the button, and what is written is kept
        // while it is being written.
        self::assertSelectorExists('form#composer button[type="submit"][data-action="composer#submit"]');
        self::assertSelectorExists('form#composer textarea[data-composer-target="text"]');
        self::assertStringContainsString(
            'composer#saveDraft',
            (string) $this->client->getCrawler()->filter('form#composer textarea')->attr('data-action'),
        );

        // The identifier the browser picks is carried to the server, which
        // saves the message under it.
        self::assertSelectorExists('form#composer [data-composer-target="clientid"]');
    }

    public function testAMessageCarriesTheSortValueTheListOrdersBy(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();
        $message = $this->postMessage($room, 'First post');

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();

        $element = $this->client->getCrawler()->filter('#message_'.$message->getKey());
        $timestamp = (int) $element->attr('data-message-timestamp');

        // Milliseconds since the epoch, which is what the browser compares
        // messages by, and what the day separator is drawn from.
        self::assertSame($timestamp, (int) $element->attr('data-sort-value'));
        self::assertGreaterThan(1_000_000_000_000, $timestamp);
        self::assertSame($message->getCreatedAt()->format('Uv'), $element->attr('data-message-timestamp'));
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }

    private function postMessage(OpenRoom $room, string $body): Message
    {
        $crawler = $this->client->request('GET', '/rooms/'.$room->getId());
        $this->client->submit($crawler->selectButton('Send')->form([
            'message[body]' => $body,
        ]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();

        $message = $this->entityManager()->getRepository(Message::class)->findOneBy(
            ['room' => $room],
            ['id' => 'DESC'],
        );
        self::assertNotNull($message);

        return $message;
    }
}
