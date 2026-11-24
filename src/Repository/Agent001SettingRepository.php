<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Repository;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agent001Setting>
 */
class Agent001SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent001Setting::class);
    }

    public function findOneByChannelCode(string $channelCode): ?Agent001Setting
    {
        return $this->findOneBy(['channelCode' => $channelCode]);
    }

    public function claimSync(Agent001Setting $setting, string $sessionId, \DateTimeImmutable $now): bool
    {
        $claimed = $this->createQueryBuilder('s')
            ->update()
            ->set('s.syncStatus', ':reading')
            ->set('s.syncSessionId', ':session')
            ->set('s.syncUpdatedAt', ':now')
            ->set('s.syncCount', '0')
            ->set('s.syncMessage', ':message')
            ->set('s.syncMessageParameters', 'NULL')
            ->andWhere('s.id = :id')
            ->andWhere(
                's.syncStatus IS NULL OR s.syncStatus <> :reading'
                . ' OR s.syncUpdatedAt IS NULL OR s.syncUpdatedAt < :stale',
            )
            ->setParameter('reading', Agent001Setting::STATUS_READING)
            ->setParameter('session', $sessionId)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter(
                'stale',
                $now->modify(sprintf('-%d seconds', Agent001Setting::STALE_AFTER_SECONDS)),
                Types::DATETIME_IMMUTABLE,
            )
            ->setParameter('message', 'agent001_sylius_ai_assistant.sync.starting')
            ->setParameter('id', $setting->getId())
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($setting);

        return 1 === $claimed;
    }

    /**
     * @return array<string, Agent001Setting>
     */
    public function findAllIndexedByChannelCode(): array
    {
        $settings = [];
        foreach ($this->findAll() as $setting) {
            $settings[$setting->getChannelCode()] = $setting;
        }

        return $settings;
    }

    /**
     * @return list<Agent001Setting>
     */
    public function findWithAgent(): array
    {
        /** @var list<Agent001Setting> $settings */
        $settings = $this->createQueryBuilder('s')
            ->andWhere('s.agentId IS NOT NULL')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $settings;
    }
}
