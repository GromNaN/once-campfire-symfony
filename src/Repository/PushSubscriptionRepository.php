<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\Type\RailsDateTimeType;
use App\Entity\Enum\MembershipInvolvement;
use App\Entity\Message;
use App\Entity\Membership;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * Drops every device of a user.
     *
     * A deactivated member no longer reads anything, so the browsers they
     * registered are removed rather than left to be notified about rooms they
     * cannot open.
     */
    public function deleteFor(User $user): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * The subscriptions a new message should reach.
     *
     * A member is told about every message when they asked for that, and about
     * the messages that name them when they asked for mentions only. Someone
     * already reading the room is left alone, since the message is on their
     * screen already, and the author is never told about their own message.
     *
     * @param list<int> $mentioneeIds the users the message mentions
     *
     * @return list<PushSubscription>
     */
    public function findForMessage(Message $message, array $mentioneeIds, \DateTimeImmutable $now): array
    {
        $builder = $this->createQueryBuilder('subscription')
            ->addSelect('user')
            ->join('subscription.user', 'user')
            ->join('user.memberships', 'membership')
            ->andWhere('membership.room = :room')
            ->andWhere('membership.user != :author')
            ->andWhere('membership.involvement != :invisible')
            ->andWhere('membership.connectedAt IS NULL OR membership.connectedAt < :threshold')
            ->setParameter('room', $message->getRoom())
            ->setParameter('author', $message->getCreator())
            ->setParameter('invisible', MembershipInvolvement::Invisible)
            // The column keeps microseconds and the type Doctrine infers from a
            // DateTimeImmutable drops them, so the comparison is bound with the
            // type the column is written with.
            ->setParameter(
                'threshold',
                $now->modify('-'.Membership::CONNECTION_TTL.' seconds'),
                RailsDateTimeType::NAME,
            );

        if ([] === $mentioneeIds) {
            $builder->andWhere('membership.involvement = :everything')
                ->setParameter('everything', MembershipInvolvement::Everything);
        } else {
            $builder->andWhere(
                'membership.involvement = :everything
                 OR (membership.involvement = :mentions AND membership.user IN (:mentionees))',
            )
                ->setParameter('everything', MembershipInvolvement::Everything)
                ->setParameter('mentions', MembershipInvolvement::Mentions)
                ->setParameter('mentionees', $mentioneeIds);
        }

        return $builder->getQuery()->getResult();
    }
}
