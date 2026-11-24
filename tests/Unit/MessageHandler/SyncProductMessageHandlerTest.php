<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\MessageHandler;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\SyncProductMessage;
use Agent001\SyliusAiAssistantPlugin\MessageHandler\SyncProductMessageHandler;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SyncProductMessageHandlerTest extends TestCase
{
    private const PRODUCT_ID = 5;

    /** @var list<array{method: string, url: string, channel: string, body: string}> */
    private array $requests = [];

    /** @var array<string, int> */
    private array $statuses = [];

    private ProductReaderInterface&MockObject $productReader;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->statuses = [];
        $this->productReader = $this->createMock(ProductReaderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testItUpsertsTheProductIntoEveryCompletedChannel(): void
    {
        $this->productReader->method('readOne')->willReturn(['id' => self::PRODUCT_ID, 'title' => 'Shirt']);

        $this->handler([$this->setting('WEB'), $this->setting('B2B')])(new SyncProductMessage(self::PRODUCT_ID));

        $this->assertSame([
            ['PUT', '/api/v1/ingest/products/5', 'channel:WEB'],
            ['PUT', '/api/v1/ingest/products/5', 'channel:B2B'],
        ], $this->sent());
        $this->assertSame(
            ['site' => 'https://web.test', 'product' => ['id' => self::PRODUCT_ID, 'title' => 'Shirt']],
            json_decode($this->requests[0]['body'], true),
        );
    }

    public function testItDeletesTheProductWhenItIsNoLongerAvailableInTheChannel(): void
    {
        $this->productReader->method('readOne')->willReturn(null);

        $this->handler([$this->setting('WEB')])(new SyncProductMessage(self::PRODUCT_ID));

        $this->assertSame([['DELETE', '/api/v1/ingest/products/5', 'channel:WEB']], $this->sent());
    }

    public function testItDeletesWithoutReadingWhenTheProductWasRemoved(): void
    {
        $this->productReader->expects($this->never())->method('readOne');

        $this->handler([$this->setting('WEB')])(
            new SyncProductMessage(self::PRODUCT_ID, SyncProductMessage::OP_DELETE),
        );

        $this->assertSame([['DELETE', '/api/v1/ingest/products/5', 'channel:WEB']], $this->sent());
    }

    public function testItTreatsAMissingRemoteProductAsDeleted(): void
    {
        $this->statuses = ['DELETE channel:WEB' => 404];
        $this->logger->expects($this->never())->method('warning');

        $this->handler([$this->setting('WEB')])(
            new SyncProductMessage(self::PRODUCT_ID, SyncProductMessage::OP_DELETE),
        );

        $this->assertCount(1, $this->requests);
    }

    public function testItLogsButDoesNotRetryChangesRejectedByTheBackend(): void
    {
        $this->productReader->method('readOne')->willReturn(['id' => self::PRODUCT_ID]);
        $this->statuses = ['PUT channel:WEB' => 422];
        $this->logger->expects($this->once())->method('warning');

        $this->handler([$this->setting('WEB')])(new SyncProductMessage(self::PRODUCT_ID));

        $this->assertCount(1, $this->requests);
    }

    #[DataProvider('retryableStatuses')]
    public function testItThrowsOnRetryableFailuresSoMessengerRetriesTheMessage(int $status): void
    {
        $this->productReader->method('readOne')->willReturn(['id' => self::PRODUCT_ID]);
        $this->statuses = ['PUT channel:WEB' => $status];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('upsert failed for product 5 in channel WEB (HTTP %d).', $status));

        $this->handler([$this->setting('WEB')])(new SyncProductMessage(self::PRODUCT_ID));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatuses(): iterable
    {
        yield 'server error' => [500];
        yield 'timeout' => [408];
        yield 'rate limited' => [429];
    }

    public function testItSkipsChannelsWhoseFirstSynchronizationHasNotFinished(): void
    {
        $this->productReader->method('readOne')->willReturn(['id' => self::PRODUCT_ID]);

        $handler = $this->handler([$this->setting('WEB', completed: false), $this->setting('B2B')]);
        $handler(new SyncProductMessage(self::PRODUCT_ID));

        $this->assertSame([['PUT', '/api/v1/ingest/products/5', 'channel:B2B']], $this->sent());
    }

    public function testItSendsToEveryChannelAndReportsAllFailuresTogether(): void
    {
        $this->productReader->method('readOne')->willReturn(['id' => self::PRODUCT_ID]);
        $this->statuses = ['PUT channel:WEB' => 503, 'PUT channel:B2B' => 503];

        try {
            $handler = $this->handler([$this->setting('WEB'), $this->setting('B2B'), $this->setting('OUTLET')]);
            $handler(new SyncProductMessage(self::PRODUCT_ID));
            $this->fail('The handler should report the failed channels.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('in channel WEB (HTTP 503)', $exception->getMessage());
            $this->assertStringContainsString('in channel B2B (HTTP 503)', $exception->getMessage());
        }

        $this->assertContains(['PUT', '/api/v1/ingest/products/5', 'channel:OUTLET'], $this->sent());
    }

    public function testItDoesNothingWhenTheStoreIsNotConnected(): void
    {
        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->expects($this->never())->method('findWithAgent');

        $this->handler([], '', $settingRepository)(new SyncProductMessage(self::PRODUCT_ID));

        $this->assertSame([], $this->requests);
    }

    /**
     * @param list<Agent001Setting> $settings
     */
    private function handler(
        array $settings,
        string $apiKey = 'api-key',
        ?Agent001SettingRepository $settingRepository = null,
    ): SyncProductMessageHandler {
        $channels = [];
        foreach ($settings as $setting) {
            $channel = $this->createMock(ChannelInterface::class);
            $channel->method('getCode')->willReturn($setting->getChannelCode());
            $channels[] = $channel;
        }
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn($channels);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('apiKey')->willReturn($apiKey);

        if (null === $settingRepository) {
            $settingRepository = $this->createMock(Agent001SettingRepository::class);
            $settingRepository->method('findWithAgent')->willReturn($settings);
        }

        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $channel = '';
            foreach ($options['headers'] ?? [] as $header) {
                if (str_starts_with(strtolower((string) $header), 'x-channel-code: ')) {
                    $channel = substr((string) $header, \strlen('x-channel-code: '));
                }
            }
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'channel' => $channel,
                'body' => (string) ($options['body'] ?? ''),
            ];

            return new MockResponse('{}', ['http_code' => $this->statuses[$method . ' ' . $channel] ?? 200]);
        });

        return new SyncProductMessageHandler(
            $connectionRepository,
            $settingRepository,
            new StoreChannels($channelRepository),
            $this->productReader,
            new Agent001Client($http, 'https://backend.test', 'https://backend.test', new NullLogger(), 0),
            $this->logger,
        );
    }

    private function setting(string $channelCode, bool $completed = true): Agent001Setting
    {
        $setting = new Agent001Setting($channelCode);
        $site = 'https://' . strtolower($channelCode) . '.test';
        $setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, $site);
        $setting->setSetupCompleted($completed);

        return $setting;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function sent(): array
    {
        return array_map(
            static fn (array $request): array => [
                $request['method'],
                (string) parse_url($request['url'], \PHP_URL_PATH),
                $request['channel'],
            ],
            $this->requests,
        );
    }
}
