<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Repository;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agent001Connection>
 */
class Agent001ConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent001Connection::class);
    }

    public function findCurrent(): ?Agent001Connection
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    public function apiKey(): string
    {
        return (string) $this->findCurrent()?->getApiKey();
    }

    public function claimConnectRevision(Agent001Connection $connection, string $revision): bool
    {
        if (null === $connection->getId() || empty($revision)) {
            return false;
        }

        $claimed = $this->createQueryBuilder('c')
            ->update()
            ->set('c.connectRevision', 'NULL')
            ->andWhere('c.id = :id')
            ->andWhere('c.connectRevision = :revision')
            ->setParameter('id', $connection->getId())
            ->setParameter('revision', $revision)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($connection);

        return 1 === $claimed;
    }
}
