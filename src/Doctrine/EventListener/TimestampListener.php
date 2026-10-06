<?php

declare(strict_types=1);

namespace App\Doctrine\EventListener;

use App\Entity\Concern\CreatedAtAware;
use App\Entity\Concern\UpdatedAtAware;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fills created_at and updated_at from the clock.
 *
 * The columns hold Rails timestamps, which are written in UTC. Reading the
 * clock here rather than in a lifecycle callback on the entity keeps the
 * entities free of framework calls and lets a test freeze time.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class TimestampListener
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $now = $this->now();

        if ($entity instanceof CreatedAtAware) {
            $entity->initializeCreatedAt($now);
        }

        if ($entity instanceof UpdatedAtAware) {
            $entity->touchUpdatedAt($now);
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof UpdatedAtAware) {
            $entity->touchUpdatedAt($this->now());
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
