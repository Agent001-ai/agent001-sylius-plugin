<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Channel;

use Agent001\SyliusAiAssistantPlugin\Channel\ChannelConfigurator;
use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Exception\Agent001Exception;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Settings\AgentList;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChannelConfiguratorTest extends TestCase
{
    private const AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    private const OTHER_AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e90';

    private Agent001ClientInterface&MockObject $client;

    private EntityManagerInterface&MockObject $entityManager;

    /** @var list<object> */
    private array $dispatched = [];

    private AgentList $agentList;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Agent001ClientInterface::class);
        $this->agentList = new AgentList($this->client, new ArrayAdapter());
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dispatched = [];
    }

    public function testAtLeastOneChannelNeedsAnAgent(): void
    {
        $this->client->expects($this->never())->method('putStoreHosts');
        $this->client->expects($this->never())->method('deleteCatalog');

        $this->expectExceptionObject(new Agent001Exception('agent001_sylius_ai_assistant.settings.agent_required'));

        $this->configurator([])->assignAgents(['WEB' => '', 'B2B' => ''], $this->request());
    }

    public function testAnAgentServesOnlyOneChannel(): void
    {
        $this->client->expects($this->never())->method('putStoreHosts');

        $this->expectExceptionObject(new Agent001Exception('agent001_sylius_ai_assistant.settings.agent_per_channel'));

        $this->configurator([])->assignAgents(['WEB' => self::AGENT, 'B2B' => self::AGENT], $this->request());
    }

    public function testTheFirstAgentRemovesTheLegacyCatalogSendsTheStoreHostsAndQueuesTheSynchronization(): void
    {
        $calls = [];
        $this->client->method('deleteCatalog')->willReturnCallback(
            static function (string $apiKey, string $catalog) use (&$calls): int {
                $calls[] = 'delete ' . $catalog;

                return 404;
            },
        );
        $this->client->method('putStoreHosts')->willReturnCallback(
            static function (string $apiKey, array $hosts) use (&$calls): bool {
                $calls[] = 'hosts ' . implode(',', $hosts);

                return true;
            },
        );
        $this->client->method('openCatalog')->with('api-key', 'channel:WEB')->willReturn(true);
        $this->client->method('updateSelectedAgents')->willReturn($this->selected(self::AGENT));
        $this->client->expects($this->once())->method('allowDomain')
            ->with('api-key', 'channel:WEB', self::AGENT, 'shop.test')
            ->willReturn(null);

        $persisted = null;
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );

        $this->configurator([])->assignAgents(['WEB' => self::AGENT, 'B2B' => ''], $this->request());

        $this->assertSame(['delete default', 'hosts shop.test,b2b.shop.test'], $calls);
        $this->assertInstanceOf(Agent001Setting::class, $persisted);
        $this->assertSame('WEB', $persisted->getChannelCode());
        $this->assertSame(self::AGENT, $persisted->getAgentId());
        $this->assertSame('embed-token', $persisted->getEmbedToken());
        $this->assertSame('https://shop.test', $persisted->getSite());
        $this->assertSame(Agent001Setting::STATUS_QUEUED, $persisted->getSyncStatus());
        $this->assertEquals([new SyncCatalogMessage('WEB')], $this->dispatched);
    }

    public function testRemovingAnAgentDeletesTheChannelCatalogAndSetting(): void
    {
        $web = $this->assigned('WEB', self::AGENT);
        $b2b = $this->assigned('B2B', self::OTHER_AGENT);

        $this->client->expects($this->once())->method('deleteCatalog')
            ->with('api-key', 'channel:WEB')
            ->willReturn(204);
        $this->client->expects($this->never())->method('putStoreHosts');
        $this->entityManager->expects($this->once())->method('remove')->with($web);

        $this->configurator(['WEB' => $web, 'B2B' => $b2b])->assignAgents(['WEB' => ''], $this->request());

        $this->assertSame([], $this->dispatched);
    }

    public function testSavingAgentsOrTogglingOneForgetsTheCachedAgentList(): void
    {
        $web = $this->assigned('WEB', self::AGENT);
        $b2b = $this->assigned('B2B', self::OTHER_AGENT);
        $this->client->method('deleteCatalog')->willReturn(204);
        $this->client->method('setAgentActive')->willReturn(null);
        $this->client->expects($this->exactly(3))->method('fetchAgents')
            ->willReturn([
                'agents' => $this->selected(self::AGENT)['agents'],
                'selected_ids' => [],
                'account_name' => 'Acme',
            ]);
        $configurator = $this->configurator(['WEB' => $web, 'B2B' => $b2b]);

        $this->agentList->fetch('api-key', 'channel:WEB');
        $this->agentList->fetch('api-key', 'channel:WEB');
        $configurator->assignAgents(['WEB' => ''], $this->request());
        $this->agentList->fetch('api-key', 'channel:WEB');
        $configurator->toggle('B2B');
        $this->agentList->fetch('api-key', 'channel:WEB');
    }

    public function testAFailedCatalogRemovalIsReported(): void
    {
        $web = $this->assigned('WEB', self::AGENT);
        $b2b = $this->assigned('B2B', self::OTHER_AGENT);
        $this->client->method('deleteCatalog')->willReturn(500);
        $this->entityManager->expects($this->never())->method('remove');

        $this->expectExceptionObject(
            new Agent001Exception('agent001_sylius_ai_assistant.settings.remote_agent_update_failed'),
        );

        $this->configurator(['WEB' => $web, 'B2B' => $b2b])->assignAgents(['WEB' => ''], $this->request());
    }

    public function testTheAgentIsNotSavedWhenItsDomainCannotBeAllowed(): void
    {
        $this->client->method('deleteCatalog')->willReturn(404);
        $this->client->method('putStoreHosts')->willReturn(true);
        $this->client->method('openCatalog')->willReturn(true);
        $this->client->method('updateSelectedAgents')->willReturn($this->selected(self::AGENT));
        $this->client->method('allowDomain')->willReturn('forbidden');
        $this->entityManager->expects($this->never())->method('persist');

        try {
            $this->configurator([])->assignAgents(['WEB' => self::AGENT], $this->request());
            $this->fail('The domain error was not reported.');
        } catch (Agent001Exception $exception) {
            $this->assertSame('agent001_sylius_ai_assistant.settings.domain_failed', $exception->getMessage());
        }

        $this->assertSame([], $this->dispatched);
    }

    /**
     * @return array{
     *     success: bool,
     *     agents: list<array{
     *         id: string,
     *         name: string,
     *         embed_token: string,
     *         active: bool,
     *         channels: list<string>,
     *     }>,
     *     selected_ids: list<string>,
     * }
     */
    private function selected(string $agentId): array
    {
        return [
            'success' => true,
            'agents' => [
                [
                    'id' => $agentId,
                    'name' => 'Shop',
                    'embed_token' => 'embed-token',
                    'active' => true,
                    'channels' => [],
                ],
            ],
            'selected_ids' => [$agentId],
        ];
    }

    private function assigned(string $channelCode, string $agentId): Agent001Setting
    {
        $setting = new Agent001Setting($channelCode);
        $setting->assignAgent($agentId, 'embed-token', true, 'https://shop.test');

        return $setting;
    }

    private function request(): Request
    {
        return Request::create('https://admin.shop.test/admin/agent001/agents', Request::METHOD_POST);
    }

    /**
     * @param array<string, Agent001Setting> $settings
     */
    private function configurator(array $settings): ChannelConfigurator
    {
        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('apiKey')->willReturn('api-key');

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findAllIndexedByChannelCode')->willReturn($settings);
        $settingRepository->method('findOneByChannelCode')->willReturnCallback(
            static fn (string $code): ?Agent001Setting => $settings[$code] ?? null,
        );

        $channels = [];
        foreach (['WEB' => 'shop.test', 'B2B' => 'b2b.shop.test'] as $code => $hostname) {
            $channel = new Channel();
            $channel->setCode($code);
            $channel->setHostname($hostname);
            $channels[] = $channel;
        }
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn($channels);
        $storeChannels = new StoreChannels($channelRepository);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $storeContextReader = $this->createMock(StoreContextReaderInterface::class);

        return new ChannelConfigurator(
            $this->client,
            $connectionRepository,
            $settingRepository,
            $storeChannels,
            $storeContextReader,
            new Synchronizer(
                $this->createMock(ProductReaderInterface::class),
                $storeContextReader,
                $this->client,
                $connectionRepository,
                $settingRepository,
                $storeChannels,
                $this->entityManager,
                new ArrayAdapter(),
                new NullLogger(),
            ),
            $this->entityManager,
            $bus,
            $this->agentList,
        );
    }
}
