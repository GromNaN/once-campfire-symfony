<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Base class of the functional tests.
 *
 * The schema is built once from the migrations, so the tests run against the
 * same schema the application ships, including the full text search table that
 * only the migration creates.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    /**
     * Tables emptied between tests. The full text search index is included
     * because it is a table of its own.
     */
    private const TABLES = [
        'accounts',
        'users',
        'rooms',
        'memberships',
        'messages',
        'boosts',
        'bans',
        'searches',
        'sessions',
        'push_subscriptions',
        'webhooks',
        'action_text_rich_texts',
        'active_storage_blobs',
        'active_storage_attachments',
        'active_storage_variant_records',
        'message_search_index',
        'messenger_messages',
    ];

    private static bool $schemaBuilt = false;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->buildSchema(static::getContainer()->get('kernel'));
        $this->truncate($this->connection());

        // A browser announces that its requests are same origin, which is what
        // the stateless CSRF protection checks first. Without it a test would
        // have to carry a double-submit token on every call, and mixing the two
        // checks across requests makes the protection refuse the later ones.
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');

        // The sign in limiter counts attempts per address in a cache the whole
        // suite shares, so without this a test would inherit the attempts of
        // the tests that ran before it.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->connection()->close();
    }

    protected function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Creates the account, its first room and the administrator, which is what
     * the first run page does.
     */
    protected function runFirstRun(string $name = 'Alice', string $emailAddress = 'alice@example.com'): void
    {
        $crawler = $this->client->request('GET', '/first_run');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form([
            'registration[name]' => $name,
            'registration[emailAddress]' => $emailAddress,
            'registration[password]' => 'correct horse battery',
        ]));

        self::assertResponseRedirects('/');
    }

    protected function signOut(): void
    {
        $this->client->request('DELETE', '/session', ['_csrf_token' => $this->csrfToken('logout')]);
        self::assertResponseRedirects('/');
    }

    /**
     * Returns the token a state-changing request has to carry.
     *
     * A stateless token is validated from the origin of the request, which the
     * test client announces on every call, so the value is the placeholder the
     * manager hands out. A browser adds a double-submit cookie on top; a test
     * does not, because it runs no script.
     */
    protected function csrfToken(string $id): string
    {
        return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
    }

    protected function findUser(string $emailAddress): User
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['emailAddress' => $emailAddress]);
        self::assertNotNull($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(string $method, string $uri, array $payload = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function buildSchema(KernelInterface $kernel): void
    {
        if (self::$schemaBuilt) {
            return;
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $output = new NullOutput();

        // The database is dropped first so the schema always comes from the
        // migrations. The --if-exists option cannot be used: it makes the
        // command list the databases, which SQLite does not support. A failure
        // here means there was nothing to drop, which SchemaTest would catch if
        // it left a stale database behind.
        $application->run(new ArrayInput([
            'command' => 'doctrine:database:drop',
            '--force' => true,
        ]), $output);

        $application->run(new ArrayInput(['command' => 'doctrine:database:create']), $output);

        $exitCode = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]), $output);

        if (0 !== $exitCode) {
            throw new \RuntimeException('The test schema could not be built from the migrations.');
        }

        self::$schemaBuilt = true;
    }

    private function truncate(Connection $connection): void
    {
        $connection->executeStatement('PRAGMA foreign_keys = OFF');

        foreach (self::TABLES as $table) {
            $connection->executeStatement(\sprintf('DELETE FROM "%s"', $table));
        }

        $connection->executeStatement('PRAGMA foreign_keys = ON');
    }
}
