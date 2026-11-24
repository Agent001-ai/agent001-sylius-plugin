<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Connect;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Connect\ConnectionManager;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Exception\Agent001Exception;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Settings\AgentList;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConnectionManagerTest extends TestCase
{
    private const CALLBACK_PATH = '/admin/agent001/callback';

    private const SETTINGS_URL = 'https://shop.test/admin/agent001';

    private AgentList $agentList;

    private int $agentRequests = 0;

    protected function setUp(): void
    {
        $this->agentRequests = 0;
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->agentRequests;

            return new MockResponse((string) json_encode(['agents' => [[
                'id' => '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f',
                'name' => 'Shop',
                'embed_token' => 'token',
                'active' => true,
            ]]]));
        });
        $this->agentList = new AgentList(
            new Agent001Client($http, 'https://backend.test', null, new NullLogger(), 0),
            new ArrayAdapter(),
        );
    }

    public function testStateIsSingleUseAndConnectsInstallation(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB');
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn([$channel]);

        $connection = new Agent001Connection();
        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('findCurrent')->willReturn($connection);
        $this->claimRevisionsOnce($connectionRepository);

        $http = new MockHttpClient(
            new MockResponse('{"api_key":"secret","account_name":"Acme"}', ['http_code' => 200]),
        );
        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findAll')->willReturn([]);

        $manager = new ConnectionManager(
            new Agent001Client($http, 'https://backend.test', 'https://backend.test', new NullLogger()),
            $connectionRepository,
            $settingRepository,
            new StoreChannels($channelRepository),
            $this->createMock(EntityManagerInterface::class),
            new ArrayAdapter(),
            $this->agentList,
        );

        $url = $manager->start(
            'https://shop.test',
            self::CALLBACK_PATH,
            self::SETTINGS_URL,
            'admin@example.com|session-a',
        );
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $this->assertSame('channel:WEB', $query['channel']);
        $this->assertSame('https://shop.test/admin/agent001/callback', $query['return_to']);

        $state = $manager->consumeState((string) $query['state'], 'admin@example.com|session-a');
        $this->assertNotNull($state);
        $this->assertNull($manager->consumeState((string) $query['state'], 'admin@example.com|session-a'));

        $this->agentList->fetch('secret', 'channel:WEB');
        $this->agentList->fetch('secret', 'channel:WEB');
        $this->assertTrue($manager->complete($state, 'code'));
        $this->agentList->fetch('secret', 'channel:WEB');
        $this->assertSame(2, $this->agentRequests);
        $this->assertSame('secret', $connection->getApiKey());
        $this->assertSame('https://shop.test', $connection->getSite());
        $this->assertFalse($manager->complete($state, 'code'));

        $connection->rotateConnectRevision();
        $this->assertFalse($manager->complete($state, 'code'));
    }

    public function testStateIsUsableOnlyByTheAdminSessionThatStartedIt(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB');
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn([$channel]);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('findCurrent')->willReturn(new Agent001Connection());

        $manager = new ConnectionManager(
            new Agent001Client(new MockHttpClient(), 'https://backend.test', 'https://backend.test', new NullLogger()),
            $connectionRepository,
            $this->createMock(Agent001SettingRepository::class),
            new StoreChannels($channelRepository),
            $this->createMock(EntityManagerInterface::class),
            new ArrayAdapter(),
            $this->agentList,
        );

        $url = $manager->start(
            'https://shop.test',
            self::CALLBACK_PATH,
            self::SETTINGS_URL,
            "admin@example.com\0session-a",
        );
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $this->assertNull($manager->consumeState((string) $query['state'], "admin@example.com\0session-b"));
        $this->assertNotNull($manager->consumeState((string) $query['state'], "admin@example.com\0session-a"));
    }

    public function testTheFirstConnectionIsCreatedByTheManager(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB');
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn([$channel]);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('findCurrent')->willReturn(null);

        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );
        $entityManager->expects($this->once())->method('flush');

        $manager = new ConnectionManager(
            new Agent001Client(new MockHttpClient(), 'https://backend.test', 'https://backend.test', new NullLogger()),
            $connectionRepository,
            $this->createMock(Agent001SettingRepository::class),
            new StoreChannels($channelRepository),
            $entityManager,
            new ArrayAdapter(),
            $this->agentList,
        );

        $manager->start('https://shop.test', self::CALLBACK_PATH, self::SETTINGS_URL, 'admin@example.com|session-a');

        $this->assertInstanceOf(Agent001Connection::class, $persisted);
        $this->assertNotNull($persisted->getConnectRevision());
    }

    public function testConnectingToAnInsecureAgent001AddressIsRefused(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB');
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn([$channel]);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->expects($this->never())->method('findCurrent');

        $manager = new ConnectionManager(
            new Agent001Client(new MockHttpClient(), 'http://agent001.example.com', null, new NullLogger()),
            $connectionRepository,
            $this->createMock(Agent001SettingRepository::class),
            new StoreChannels($channelRepository),
            $this->createMock(EntityManagerInterface::class),
            new ArrayAdapter(),
            $this->agentList,
        );

        $this->expectExceptionObject(new Agent001Exception('agent001_sylius_ai_assistant.connect.insecure_base_url'));

        $manager->start('https://shop.test', self::CALLBACK_PATH, self::SETTINGS_URL, 'admin@example.com|session-a');
    }

    public function testReconnectKeepsOnlyAgentsConfirmedByTheNewConnection(): void
    {
        $kept = new Agent001Setting('WEB');
        $kept->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, 'https://shop.test');
        $foreign = new Agent001Setting('B2B');
        $foreign->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e90', 'token', true, 'https://b2b.test');
        $detached = new Agent001Setting('OUTLET');
        $detached->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e91', 'token', true, 'https://outlet.test');

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findAll')->willReturn([$kept, $foreign, $detached]);

        $connection = new Agent001Connection();
        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('findCurrent')->willReturn($connection);
        $this->claimRevisionsOnce($connectionRepository);

        $removed = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $agents = ['agents' => [
            ['id' => $kept->getAgentId(), 'name' => 'Kept', 'embed_token' => 'token', 'channels' => ['channel:WEB']],
            ['id' => $detached->getAgentId(), 'name' => 'Detached', 'embed_token' => 'token', 'channels' => []],
        ]];
        $manager = new ConnectionManager(
            new Agent001Client(new MockHttpClient([
                new MockResponse('{"api_key":"other","account_name":"Other"}', ['http_code' => 200]),
                new MockResponse((string) json_encode($agents), ['http_code' => 200]),
            ]), 'https://backend.test', 'https://backend.test', new NullLogger()),
            $connectionRepository,
            $settingRepository,
            new StoreChannels($this->createMock(ChannelRepositoryInterface::class)),
            $entityManager,
            new ArrayAdapter(),
            $this->agentList,
        );

        $state = [
            'site' => 'https://shop.test',
            'settings_url' => self::SETTINGS_URL,
            'revision' => $connection->rotateConnectRevision(),
        ];

        $this->assertTrue($manager->complete($state, 'code'));
        $this->assertSame('other', $connection->getApiKey());
        $this->assertSame([$foreign, $detached], $removed);
    }

    private function claimRevisionsOnce(MockObject&Agent001ConnectionRepository $repository): void
    {
        $claimed = [];
        $repository->method('claimConnectRevision')->willReturnCallback(
            static function (Agent001Connection $connection, string $revision) use (&$claimed): bool {
                if (isset($claimed[$revision]) || $connection->getConnectRevision() !== $revision) {
                    return false;
                }
                $claimed[$revision] = true;

                return true;
            },
        );
    }
}
