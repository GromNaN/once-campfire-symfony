<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers the link preview the composer asks for when someone pastes an address.
 *
 * The page is never really read: the client the preview fetcher uses is
 * replaced with one that answers from a table, so a test says what a page
 * claims about itself and checks what the composer is told.
 */
final class UnfurlTest extends DatabaseTestCase
{
    /**
     * A page of the public internet. An address that is a number rather than a
     * name needs no name resolution, which keeps a test off the network.
     */
    private const PAGE = 'http://93.184.216.34/article';
    private const PICTURE = 'http://93.184.216.34/pic.png';

    /** @var list<string> */
    private array $requests = [];

    public function testAPageThatDescribesItselfBecomesAPreview(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="A title">
                <meta property="og:description" content="A description">
                <meta property="og:image" content="http://93.184.216.34/pic.png">
                </head></html>
                HTML),
            'HEAD '.self::PICTURE => $this->file('image/png'),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => self::PAGE]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'title' => 'A title',
            'url' => self::PAGE,
            'image' => self::PICTURE,
            'description' => 'A description',
        ], $preview);
    }

    public function testTheAddressThePageDeclaresIsUsed(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="A title">
                <meta property="og:description" content="A description">
                <meta property="og:url" content="http://93.184.216.34/canonical">
                </head></html>
                HTML),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => self::PAGE]);

        self::assertSame('http://93.184.216.34/canonical', $preview['url']);
    }

    public function testAnAddressThePageDeclaresThatCannotBeReachedIsIgnored(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="A title">
                <meta property="og:description" content="A description">
                <meta property="og:url" content="http://127.0.0.1/private">
                </head></html>
                HTML),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => self::PAGE]);

        self::assertSame(self::PAGE, $preview['url']);
    }

    public function testAPageThatSaysNothingHasNoPreview(): void
    {
        $this->runFirstRun();
        $this->mockHttp(['GET '.self::PAGE => $this->html('<html><head><title>Nothing</title></head></html>')]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => self::PAGE]));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAPageWithoutADescriptionHasNoPreview(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html('<html><head><meta property="og:title" content="A title"></head></html>'),
        ]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => self::PAGE]));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAPageThatIsNotAPageHasNoPreview(): void
    {
        $this->runFirstRun();
        $this->mockHttp(['GET '.self::PAGE => new MockResponse('{"a":1}', ['response_headers' => ['content-type' => 'application/json']])]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => self::PAGE]));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAPictureThatIsNotAPictureIsLeftOutOfThePreview(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="A title">
                <meta property="og:description" content="A description">
                <meta property="og:image" content="http://93.184.216.34/pic.svg">
                </head></html>
                HTML),
            'HEAD http://93.184.216.34/pic.svg' => $this->file('image/svg+xml'),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => self::PAGE]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'title' => 'A title',
            'url' => self::PAGE,
            'image' => null,
            'description' => 'A description',
        ], $preview);
    }

    public function testTheLastTagOfAPageWins(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="The first title">
                <meta property="og:title" content="The last title">
                <meta property="og:description" content="A description">
                </head></html>
                HTML),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => self::PAGE]);

        self::assertSame('The last title', $preview['title']);
    }

    public function testTagsAreShownAsText(): void
    {
        $this->runFirstRun();
        $this->mockHttp([
            'GET '.self::PAGE => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="A &lt;b&gt;title&lt;/b&gt;">
                <meta property="og:description" content="A description">
                </head></html>
                HTML),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => self::PAGE]);

        self::assertSame('A title', $preview['title']);
    }

    public function testAnAddressOnThePrivateNetworkIsNeverRead(): void
    {
        $this->runFirstRun();
        $this->mockHttp([]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'http://127.0.0.1:8080/admin']));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->requests);
    }

    public function testALinkToAFileIsNeverRead(): void
    {
        $this->runFirstRun();
        $this->mockHttp([]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'http://93.184.216.34/archive.zip']));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->requests);
    }

    public function testAnAddressThatIsNotAWebAddressIsNeverRead(): void
    {
        $this->runFirstRun();
        $this->mockHttp([]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => 'file:///etc/passwd']));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->requests);
    }

    public function testATweetIsReadThroughTheMirrorThatDescribesIt(): void
    {
        if ([] === gethostbynamel('x.com')) {
            self::markTestSkipped('The test needs to resolve the name of the tweet host.');
        }

        $this->runFirstRun();
        $this->mockHttp([
            'GET https://fxtwitter.com/jerome/status/1' => $this->html(<<<'HTML'
                <html><head>
                <meta property="og:title" content="A tweet">
                <meta property="og:description" content="A description">
                </head></html>
                HTML),
        ]);

        $preview = $this->json('POST', '/unfurl_link', ['url' => 'https://x.com/jerome/status/1']);

        self::assertSame('A tweet', $preview['title']);
        self::assertSame(['GET https://fxtwitter.com/jerome/status/1'], $this->requests);
    }

    public function testAnAddressIsRequired(): void
    {
        $this->runFirstRun();
        $this->mockHttp([]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnEmptyAddressIsRefused(): void
    {
        $this->runFirstRun();
        $this->mockHttp([]);

        $this->client->request('POST', '/unfurl_link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url' => '  ']));

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Replaces the client the preview fetcher reads pages with, and remembers
     * every address a test asked for.
     *
     * @param array<string, MockResponse> $responses keyed by "METHOD URL"
     */
    private function mockHttp(array $responses): void
    {
        // The client reboots the kernel between requests, which would put the
        // real client back in place, so the kernel is kept as it is.
        $this->client->disableReboot();

        static::getContainer()->set('app.opengraph.http_client', new MockHttpClient(
            function (string $method, string $url) use ($responses): MockResponse {
                $this->requests[] = $method.' '.$url;

                // An address a test did not prepare answers nothing, which is
                // what a site that does not answer looks like.
                return $responses[$method.' '.$url] ?? new MockResponse('', ['info' => ['http_code' => 404]]);
            },
        ));
    }

    private function html(string $html): MockResponse
    {
        return new MockResponse($html, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
    }

    private function file(string $contentType): MockResponse
    {
        return new MockResponse('', ['response_headers' => ['content-type' => $contentType]]);
    }
}
