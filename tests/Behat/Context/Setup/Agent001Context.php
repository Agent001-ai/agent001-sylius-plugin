<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Setup;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;
use Webmozart\Assert\Assert;

final class Agent001Context implements Context
{
    public const AGENT_ID = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    public const SITE = 'http://localhost';

    private const AGENTS_PATH = '/api/v1/integrations/me/agents';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedStorageInterface $sharedStorage,
        private readonly FakeAgent001Backend $backend,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[BeforeScenario]
    public function resetAgent001(): void
    {
        FakeAgent001Backend::reset();
        $this->cache->clear();
    }

    #[Given('the store is connected with Agent001')]
    public function theStoreIsConnectedWithAgent001(): void
    {
        $connection = new Agent001Connection();
        $connection->connect(apiKey: FakeAgent001Backend::API_KEY, accountName: 'Test account', site: self::SITE);

        $this->entityManager->persist($connection);
        $this->entityManager->flush();
    }

    #[Given('the Agent001 account has an agent :name')]
    public function theAgent001AccountHasAnAgent(string $name): void
    {
        $agent = [
            'id' => self::AGENT_ID,
            'name' => $name,
            'embed_token' => 'embed-token',
            'active' => true,
            'channels' => [],
        ];

        $this->backend->respond(
            method: 'GET',
            path: self::AGENTS_PATH,
            status: 200,
            body: ['agents' => [$agent], 'selected_ids' => []],
        );
        $this->backend->respond(
            method: 'PUT',
            path: self::AGENTS_PATH,
            status: 200,
            body: ['agents' => [$agent], 'selected_ids' => [self::AGENT_ID]],
        );
    }

    #[Given('the :channel channel has an Agent001 agent assigned')]
    public function theChannelHasAnAgent001AgentAssigned(ChannelInterface $channel): void
    {
        $channelCode = $channel->getCode();
        Assert::notNull($channelCode);

        $setting = new Agent001Setting($channelCode);
        $setting->assignAgent(agentId: self::AGENT_ID, embedToken: 'embed-token', active: true, site: self::SITE);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();
        $this->sharedStorage->set('agent001_setting', $setting);
    }

    #[Given('its first catalog synchronization has finished')]
    public function itsFirstCatalogSynchronizationHasFinished(): void
    {
        $this->setting()->setSetupCompleted(true);
        $this->entityManager->flush();
    }

    #[Given('its Agent001 agent has been disabled')]
    public function itsAgent001AgentHasBeenDisabled(): void
    {
        $this->setting()->setAgentActive(false);
        $this->entityManager->flush();
    }

    private function setting(): Agent001Setting
    {
        $setting = $this->sharedStorage->get('agent001_setting');
        Assert::isInstanceOf($setting, Agent001Setting::class);

        return $setting;
    }
}
