<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\MessageHandler;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\CheckSyncSessionMessage;
use Agent001\SyliusAiAssistantPlugin\MessageHandler\CheckSyncSessionMessageHandler;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Sync\IndexingWatcher;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final class CheckSyncSessionMessageHandlerTest extends TestCase
{
    private Agent001Setting $setting;

    /** @var list<string> */
    private array $requests = [];

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->setting = new Agent001Setting('WEB');
        $this->setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, 'https://shop.test');
        $this->setting->reportSync(Agent001Setting::STATUS_INDEXING, 'agent001_sylius_ai_assistant.sync.sent', 3);
        (new \ReflectionProperty(Agent001Setting::class, 'syncSessionId'))->setValue($this->setting, 'current');
        $this->requests = [];
        $this->dispatched = [];
    }

    public function testAMessageOfAnOlderSessionIsIgnored(): void
    {
        $this->handler(['status' => 'succeeded'])(new CheckSyncSessionMessage('WEB', 'older'));

        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->dispatched);
        $this->assertSame(Agent001Setting::STATUS_INDEXING, $this->setting->getSyncStatus());
    }

    public function testAMessageOfAnUnknownChannelIsIgnored(): void
    {
        $this->handler(['status' => 'succeeded'])(new CheckSyncSessionMessage('OTHER', 'current'));

        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->dispatched);
    }

    public function testASessionStillIndexingIsCheckedAgain(): void
    {
        $this->handler(['status' => 'processing'])(new CheckSyncSessionMessage('WEB', 'current'));

        $this->assertSame(['GET /api/v1/ingest/sessions/current'], $this->requests);
        $this->assertSame(Agent001Setting::STATUS_INDEXING, $this->setting->getSyncStatus());
        $this->assertEquals([new CheckSyncSessionMessage('WEB', 'current')], $this->dispatched);
    }

    public function testAFinishedSessionCompletesTheSetupWithoutFurtherChecks(): void
    {
        $this->handler(['status' => 'succeeded'])(new CheckSyncSessionMessage('WEB', 'current'));

        $this->assertSame(['GET /api/v1/ingest/sessions/current'], $this->requests);
        $this->assertSame(Agent001Setting::STATUS_SUCCESS, $this->setting->getSyncStatus());
        $this->assertTrue($this->setting->isWidgetVisible());
        $this->assertSame([], $this->dispatched);
    }

    /**
     * @param array<string, string> $session
     */
    private function handler(array $session): CheckSyncSessionMessageHandler
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($session): MockResponse {
            $this->requests[] = $method . ' ' . (string) parse_url($url, \PHP_URL_PATH);

            return new MockResponse((string) json_encode($session), ['http_code' => 200]);
        });

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('apiKey')->willReturn('api-key');

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findOneByChannelCode')->willReturnCallback(
            fn (string $code): ?Agent001Setting => 'WEB' === $code ? $this->setting : null,
        );

        $synchronizer = new Synchronizer(
            $this->createMock(ProductReaderInterface::class),
            $this->createMock(StoreContextReaderInterface::class),
            new Agent001Client($http, 'https://backend.test', null, new NullLogger(), 0),
            $connectionRepository,
            $settingRepository,
            new StoreChannels($this->createMock(ChannelRepositoryInterface::class)),
            $this->createMock(EntityManagerInterface::class),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $sendersLocator = $this->createMock(SendersLocatorInterface::class);
        $sendersLocator->method('getSenders')->willReturn([new InMemoryTransport()]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (Envelope $envelope): Envelope {
            $this->dispatched[] = $envelope->getMessage();

            return $envelope;
        });

        return new CheckSyncSessionMessageHandler(
            $settingRepository,
            $synchronizer,
            new IndexingWatcher($bus, $sendersLocator),
        );
    }
}
