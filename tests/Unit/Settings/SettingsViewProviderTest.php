<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Settings;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Settings\AgentList;
use Agent001\SyliusAiAssistantPlugin\Settings\SettingsViewProvider;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class SettingsViewProviderTest extends TestCase
{
    private const WEB_AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    private const B2B_AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e90';

    /** @var list<string> */
    private array $requests = [];

    private EntityManagerInterface&MockObject $entityManager;

    private TaxonInterface $taxon;

    private ArrayAdapter $agentCache;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->agentCache = new ArrayAdapter();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->taxon = $this->createMock(TaxonInterface::class);
    }

    public function testDisconnectedStoreShowsChannelsWithoutAskingTheBackend(): void
    {
        $this->entityManager->expects($this->never())->method('flush');

        $view = $this->provider(null, [])->view();

        $this->assertSame([], $this->requests);
        $this->assertNull($view['connection']);
        $this->assertSame(['WEB', 'B2B'], array_keys($view['channels']));
        $this->assertSame([], $view['agents']);
        $this->assertFalse($view['agents_failed']);
        $this->assertSame([], $view['taxons']);
        $this->assertSame(['WEB', 'B2B'], array_keys($view['create_agent_urls']));
        $this->assertStringContainsString('channel=channel%3AWEB', $view['create_agent_urls']['WEB']);
    }

    public function testConnectedStoreAppliesTheAgentStateReportedByTheBackend(): void
    {
        $setting = $this->setting('WEB', self::WEB_AGENT, active: true);
        $connection = $this->connection();
        $this->entityManager->expects($this->once())->method('flush');

        $view = $this->provider($connection, ['WEB' => $setting], new MockResponse((string) json_encode([
            'account_name' => 'Renamed account',
            'selected_ids' => [self::WEB_AGENT],
            'agents' => [
                ['id' => self::WEB_AGENT, 'name' => 'Web', 'embed_token' => 'token', 'active' => false],
                ['id' => self::B2B_AGENT, 'name' => 'B2B', 'embed_token' => 'token', 'channels' => ['channel:B2B']],
            ],
        ])))->view();

        $this->assertSame(['GET /api/v1/integrations/me/agents'], $this->requests);
        $this->assertFalse($setting->isAgentActive());
        $this->assertSame('Renamed account', $connection->getAccountName());
        $this->assertCount(2, $view['agents']);
        $this->assertFalse($view['agents_failed']);
        $this->assertSame([self::WEB_AGENT => 'WEB', self::B2B_AGENT => 'B2B'], $view['owners']);
        $this->assertSame(['WEB' => [$this->taxon]], $view['taxons']);
    }

    public function testTheAgentListIsFetchedOnceForRepeatedVisits(): void
    {
        $settings = ['WEB' => $this->setting('WEB', self::WEB_AGENT, active: true)];
        $agents = (string) json_encode([
            'agents' => [['id' => self::WEB_AGENT, 'name' => 'Web', 'embed_token' => 'token', 'active' => true]],
        ]);

        $first = $this->provider($this->connection(), $settings, new MockResponse($agents))->view();
        $second = $this->provider($this->connection(), $settings, new MockResponse($agents))->view();

        $this->assertSame(['GET /api/v1/integrations/me/agents'], $this->requests);
        $this->assertSame($first['agents'], $second['agents']);
        $this->assertCount(1, $second['agents']);
    }

    public function testAgentsUnavailableAreReportedWithoutChangingSettings(): void
    {
        $setting = $this->setting('WEB', self::WEB_AGENT, active: true);
        $this->entityManager->expects($this->never())->method('flush');

        $unavailable = new MockResponse('', ['http_code' => 503]);

        $view = $this->provider($this->connection(), ['WEB' => $setting], $unavailable)->view();

        $this->assertTrue($view['agents_failed']);
        $this->assertSame([], $view['agents']);
        $this->assertTrue($setting->isAgentActive());
        $this->assertSame([self::WEB_AGENT => 'WEB'], $view['owners']);
    }

    public function testProgressReportsEveryChannelSetting(): void
    {
        $setting = $this->setting('WEB', self::WEB_AGENT, active: true);
        $setting->reportSync(Agent001Setting::STATUS_READING, 'agent001_sylius_ai_assistant.sync.progress', 3, [
            '%sent%' => 3,
            '%total%' => 9,
        ]);

        $progress = $this->provider($this->connection(), ['WEB' => $setting])->progress();

        $this->assertSame(['WEB' => [
            'status' => Agent001Setting::STATUS_READING,
            'message' => 'Sent 3 of 9 products...',
            'count' => 3,
            'running' => true,
        ]], $progress);
    }

    /**
     * @param array<string, Agent001Setting> $settings
     */
    private function provider(
        ?Agent001Connection $connection,
        array $settings,
        ?MockResponse $agents = null,
    ): SettingsViewProvider {
        $channels = [];
        foreach (['WEB', 'B2B'] as $code) {
            $channel = $this->createMock(ChannelInterface::class);
            $channel->method('getCode')->willReturn($code);
            $channels[] = $channel;
        }
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn($channels);
        $storeChannels = new StoreChannels($channelRepository);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('findOneBy')->willReturn($connection);
        $connectionRepository->method('apiKey')->willReturn((string) $connection?->getApiKey());

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findAllIndexedByChannelCode')->willReturn($settings);

        $storeContextReader = $this->createMock(StoreContextReaderInterface::class);
        $storeContextReader->method('taxons')->willReturn([$this->taxon]);

        $http = new MockHttpClient(function (string $method, string $url) use ($agents): MockResponse {
            $this->requests[] = $method . ' ' . (string) parse_url($url, \PHP_URL_PATH);

            return $agents ?? new MockResponse('{}');
        });
        $client = new Agent001Client($http, 'https://backend.test', 'https://backend.test', new NullLogger(), 0);

        return new SettingsViewProvider(
            connectionRepository: $connectionRepository,
            settingRepository: $settingRepository,
            channels: $storeChannels,
            storeContextReader: $storeContextReader,
            synchronizer: new Synchronizer(
                productReader: $this->createMock(ProductReaderInterface::class),
                storeContextReader: $storeContextReader,
                client: $client,
                connectionRepository: $connectionRepository,
                settingRepository: $settingRepository,
                channels: $storeChannels,
                entityManager: $this->entityManager,
                cache: new ArrayAdapter(),
                logger: new NullLogger(),
            ),
            client: $client,
            entityManager: $this->entityManager,
            translator: $this->translator(),
            agentList: new AgentList($client, $this->agentCache),
        );
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'agent001_sylius_ai_assistant.sync.progress' => 'Sent %sent% of %total% products...',
        ], 'en');

        return $translator;
    }

    private function connection(): Agent001Connection
    {
        $connection = new Agent001Connection();
        $connection->connect('api-key', 'Account', 'https://shop.test');

        return $connection;
    }

    private function setting(string $channelCode, string $agentId, bool $active): Agent001Setting
    {
        $setting = new Agent001Setting($channelCode);
        $setting->assignAgent($agentId, 'token', $active, 'https://shop.test');
        $setting->setSetupCompleted(true);

        return $setting;
    }
}
