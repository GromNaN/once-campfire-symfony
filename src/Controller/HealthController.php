<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Tells a supervisor whether the application is fit to serve.
 *
 * A container that has booted but lost its database is not healthy, so the
 * check reaches the database rather than only answering that the framework is
 * running. It is public and says nothing about the installation.
 */
final class HealthController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/up', name: 'health', methods: ['GET'])]
    public function __invoke(): Response
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (Throwable) {
            return new Response('The database is not reachable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new Response('OK');
    }
}
