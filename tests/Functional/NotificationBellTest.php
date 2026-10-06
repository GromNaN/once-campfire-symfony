<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OpenRoom;

/**
 * Covers the bell of a room: the button that asks for notifications, the
 * frame that replaces it with the setting of the room, and the help shown to a
 * reader who refused, which is written for their own browser and system.
 */
final class NotificationBellTest extends DatabaseTestCase
{
    private const CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const SAFARI_ON_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    /**
     * An old Safari, which the application refuses.
     */
    private const OLD_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Safari/605.1.15';

    /**
     * The robot Apple Messages sends to fetch a shared link. It claims to be
     * an old Safari as well as two other crawlers at once.
     */
    private const APPLE_MESSAGES = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_1) AppleWebKit/601.2.4 (KHTML, like Gecko) Version/9.0.2 Safari/601.2.4 facebookexternalhit/1.1 Facebot Twitterbot/1.0';

    public function testTheRoomCarriesTheBellThatAsksForNotifications(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId());

        self::assertResponseIsSuccessful();

        // The bell asks the browser to register, and the frame waits for the
        // answer before it loads the setting of the room.
        self::assertSelectorExists('[data-controller="notifications"][data-notifications-subscriptions-url-value="/users/me/push_subscriptions"]');
        self::assertSelectorExists('[data-notifications-target="bell"]');
        self::assertSelectorExists(
            'turbo-frame#room_'.$room->getId().'_involvement'
            .'[data-action="notifications:ready@window->turbo-frame#load"]'
            .'[data-turbo-frame-url-param="/rooms/'.$room->getId().'/involvement"]',
        );

        // And a reader who refuses is shown how to allow notifications again.
        self::assertSelectorExists('[data-notifications-target="notAllowedNotice"]');
        self::assertSelectorTextContains('[data-notifications-target="notAllowedNotice"]', 'Notifications aren');
    }

    public function testTheNotificationHelpIsWrittenForTheBrowserOfTheReader(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId(), [], [], ['HTTP_USER_AGENT' => self::CHROME_ON_MAC]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-notifications-target="notAllowedNotice"]', 'Check your Chrome settings');
        self::assertSelectorTextContains('[data-notifications-target="notAllowedNotice"]', 'Check your macOS settings');
    }

    public function testAnIphoneIsOnlyToldAboutItsSystemSettings(): void
    {
        $this->runFirstRun();
        $room = $this->openRoom();

        $this->client->request('GET', '/rooms/'.$room->getId(), [], [], ['HTTP_USER_AGENT' => self::SAFARI_ON_IPHONE]);

        self::assertResponseIsSuccessful();

        $help = $this->client->getCrawler()->filter('[data-notifications-target="notAllowedNotice"]')->text();

        // Safari on an iPhone only allows notifications from the system
        // settings, so the steps for the browser are left out.
        self::assertStringNotContainsString('Check your Safari settings', $help);
        self::assertStringContainsString('Check your iPhone settings', $help);
    }

    public function testALinkSharedThroughAppleMessagesIsNotToldItsBrowserIsTooOld(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/', [], [], ['HTTP_USER_AGENT' => self::APPLE_MESSAGES]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Campfire');
        self::assertSelectorTextNotContains('title', 'Unsupported browser');
    }

    public function testAnOldBrowserIsToldToUpgrade(): void
    {
        $this->runFirstRun();

        $this->client->request('GET', '/', [], [], ['HTTP_USER_AGENT' => self::OLD_SAFARI]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Unsupported browser');
    }

    private function openRoom(): OpenRoom
    {
        $room = $this->entityManager()->getRepository(OpenRoom::class)->findOneBy([]);
        self::assertNotNull($room);

        return $room;
    }
}
