<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Sync;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SynchronizerTest extends TestCase
{
    private const MESSAGE = 'agent001_sylius_ai_assistant.sync.';

    private const CHANNEL_CODE = 'WEB';

    private const SITE = 'https://shop.test';

    private const STORE_CONTEXT = [
        ['type' => 'category', 'id' => 'taxon:t', 'title' => 'T', 'content' => 'C', 'url' => ''],
    ];

    private Agent001Setting $setting;

    private BufferingLogger $logger;

    /**
     * @var list<array{
     *     method: string,
     *     url: string,
     *     headers: array<string, string>,
     *     body: array<string, mixed>,
     *     bytes: int,
     * }>
     */
    private array $requests = [];

    private ?\Closure $onRequest = null;

    /** @var list<ResponseInterface> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->setting = new Agent001Setting(self::CHANNEL_CODE);
        $this->setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, self::SITE);
        $this->setting->queueSync();
        $this->requests = [];
        $this->responses = [];
        $this->onRequest = null;
        $this->logger = new BufferingLogger();
    }

    public function testAFailedSynchronizationIsLoggedWithTheChannel(): void
    {
        $failure = new \RuntimeException('Unexpected failure.');
        $this->onRequest = static function () use ($failure): never {
            throw $failure;
        };

        $result = $this->synchronizer($this->products(1))->sync(self::CHANNEL_CODE);

        $this->assertFalse($result['success']);
        $this->assertSame(Agent001Setting::STATUS_FAILED, $this->setting->getSyncStatus());
        $this->assertSame(
            [[LogLevel::ERROR, 'Agent001 catalog synchronization failed.', [
                'exception' => $failure,
                'channel' => self::CHANNEL_CODE,
            ]]],
            $this->logger->cleanLogs(),
        );
    }

    public function testItSendsTheCatalogInBatchesAndMarksOnlyTheLastOneFinal(): void
    {
        $result = $this->synchronizer($this->products(1001))->sync(self::CHANNEL_CODE);

        $this->assertSame(
            [
                'success' => true,
                'message' => self::MESSAGE . 'sent',
                'parameters' => ['%count%' => 1001],
                'count' => 1001,
            ],
            $result,
        );
        $this->assertSame(self::MESSAGE . 'sent', $this->setting->getSyncMessage());
        $this->assertSame(['%count%' => 1001], $this->setting->getSyncMessageParameters());
        $this->assertSame(['0/partial', '1/partial', '2/final'], $this->sent('headers', 'x-agent001-batch'));
        $this->assertSame([500, 500, 1], array_map(
            static fn (array $request): int => count($request['body']['products']),
            $this->requests,
        ));
        $this->assertSame(Agent001Setting::STATUS_INDEXING, $this->setting->getSyncStatus());
        $this->assertSame(1001, $this->setting->getSyncCount());
    }

    public function testItSendsTheStoreContextOnlyWithTheFirstBatch(): void
    {
        $this->synchronizer($this->products(1001))->sync(self::CHANNEL_CODE);

        $this->assertSame([true, false, false], $this->sent('body', 'is_first'));
        $this->assertSame([self::STORE_CONTEXT, [], []], $this->sent('body', 'store_context'));
    }

    public function testItRejectsCatalogsAboveTheLimitWithoutSendingAnything(): void
    {
        $result = $this->synchronizer([], Synchronizer::MAX_PRODUCTS + 1)->sync(self::CHANNEL_CODE);

        $this->assertFalse($result['success']);
        $this->assertSame(self::MESSAGE . 'too_many_products', $result['message']);
        $this->assertSame([], $this->requests);
        $this->assertSame(Agent001Setting::STATUS_FAILED, $this->setting->getSyncStatus());
    }

    public function testItSplitsBatchesAboveTheBodyLimitAndKeepsBatchIndexesGrowing(): void
    {
        $products = $this->products(3, 3 * 1024 * 1024);

        $result = $this->synchronizer($products)->sync(self::CHANNEL_CODE);

        $this->assertTrue($result['success']);
        $this->assertSame(['0/partial', '1/final'], $this->sent('headers', 'x-agent001-batch'));
        $this->assertSame([[1], [2, 3]], array_map(
            static fn (array $request): array => array_column($request['body']['products'], 'id'),
            $this->requests,
        ));
        foreach ($this->requests as $request) {
            $this->assertLessThanOrEqual(Agent001ClientInterface::MAX_BATCH_BYTES, $request['bytes']);
        }
    }

    public function testEveryRequestBodyIncludingTheStoreContextStaysWithinTheLimit(): void
    {
        $products = $this->products(2, (int) (7.9 * 1024 * 1024 / 2));
        $storeContext = [
            [
                'type' => 'category',
                'id' => 'taxon:t',
                'title' => 'T',
                'content' => str_repeat('c', 512 * 1024),
                'url' => '',
            ],
        ];

        $result = $this->synchronizer($products, storeContext: $storeContext)->sync(self::CHANNEL_CODE);

        $this->assertTrue($result['success']);
        $this->assertSame(['0/partial', '1/final'], $this->sent('headers', 'x-agent001-batch'));
        $this->assertSame([$storeContext, []], $this->sent('body', 'store_context'));
        foreach ($this->requests as $request) {
            $this->assertLessThanOrEqual(Agent001ClientInterface::MAX_BATCH_BYTES, $request['bytes']);
        }
    }

    public function testItStopsSendingWhenTheSynchronizationIsReplacedMidway(): void
    {
        $this->onRequest = function (): void {
            $this->set($this->setting, 'syncSessionId', 'newer-session');
        };

        $result = $this->synchronizer($this->products(1001))->sync(self::CHANNEL_CODE);

        $this->assertSame(
            ['success' => false, 'message' => self::MESSAGE . 'replaced', 'parameters' => [], 'count' => 500],
            $result,
        );
        $this->assertCount(1, $this->requests);
        $this->assertNotSame(Agent001Setting::STATUS_INDEXING, $this->setting->getSyncStatus());
    }

    public function testItFailsWithTheProductIdWhenOneProductIsAboveTheBodyLimit(): void
    {
        $products = [['id' => 7, 'description' => str_repeat('x', Agent001ClientInterface::MAX_BATCH_BYTES)]];

        $result = $this->synchronizer($products)->sync(self::CHANNEL_CODE);

        $this->assertFalse($result['success']);
        $this->assertSame(self::MESSAGE . 'product_too_large', $result['message']);
        $this->assertSame(['%id%' => 7], $this->setting->getSyncMessageParameters());
        $this->assertSame([], $this->requests);
        $this->assertSame(Agent001Setting::STATUS_FAILED, $this->setting->getSyncStatus());
    }

    public function testItRejectsASecondSynchronizationWhileOneIsReading(): void
    {
        $result = $this->synchronizer($this->products(1), claimed: false)->sync(self::CHANNEL_CODE);

        $this->assertSame(
            ['success' => false, 'message' => self::MESSAGE . 'already_running', 'parameters' => [], 'count' => 0],
            $result,
        );
        $this->assertSame([], $this->requests);
        $this->assertSame(Agent001Setting::STATUS_QUEUED, $this->setting->getSyncStatus());
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, int|string> $expectedParameters
     */
    #[DataProvider('indexingResults')]
    public function testRefreshConcludesTheIndexingSession(
        int $status,
        array $session,
        int $age,
        string $expectedStatus,
        string $expectedMessage,
        array $expectedParameters = [],
    ): void {
        $this->setting->reportSync(Agent001Setting::STATUS_INDEXING, self::MESSAGE . 'sent', 3, ['%count%' => 3]);
        $this->set($this->setting, 'syncSessionId', 'session');
        $this->set($this->setting, 'syncUpdatedAt', new \DateTimeImmutable(sprintf('-%d seconds', $age)));
        foreach ([1, 2, 3] as $attempt) {
            $this->responses[] = new MockResponse((string) json_encode($session), ['http_code' => $status]);
        }

        $this->synchronizer([])->refresh($this->setting);

        $this->assertSame($expectedStatus, $this->setting->getSyncStatus());
        $this->assertSame(self::MESSAGE . $expectedMessage, $this->setting->getSyncMessage());
        $this->assertSame($expectedParameters, $this->setting->getSyncMessageParameters());
        $this->assertSame(Agent001Setting::STATUS_SUCCESS === $expectedStatus, $this->setting->isSetupCompleted());
        $this->assertNotEmpty($this->requests);
        foreach ($this->requests as $request) {
            $this->assertSame('GET', $request['method']);
            $this->assertStringEndsWith('/api/v1/ingest/sessions/session', $request['url']);
        }
    }

    /**
     * @return iterable<string, array{
     *     0: int,
     *     1: array<string, mixed>,
     *     2: int,
     *     3: string,
     *     4: string,
     *     5?: array<string, int|string>,
     * }>
     */
    public static function indexingResults(): iterable
    {
        yield 'replaced session' => [404, [], 0, Agent001Setting::STATUS_FAILED, 'replaced'];
        yield 'rejected session' => [403, [], 0, Agent001Setting::STATUS_FAILED, 'indexing_failed', ['%error%' => '']];
        yield 'succeeded' => [
            200,
            ['status' => 'succeeded'],
            0,
            Agent001Setting::STATUS_SUCCESS,
            'synced',
            ['%count%' => 3],
        ];
        yield 'failed with error' => [
            200,
            ['status' => 'failed', 'error' => ' Bad data '],
            0,
            Agent001Setting::STATUS_FAILED,
            'indexing_failed',
            ['%error%' => 'Bad data'],
        ];
        yield 'still indexing' => [
            200,
            ['status' => 'running'],
            60,
            Agent001Setting::STATUS_INDEXING,
            'sent',
            ['%count%' => 3],
        ];
        yield 'indexing timeout' => [
            200,
            ['status' => 'running'],
            Synchronizer::INDEXING_TIMEOUT_SECONDS + 60,
            Agent001Setting::STATUS_FAILED,
            'indexing_timeout',
        ];
        yield 'retryable error keeps waiting' => [
            429,
            [],
            60,
            Agent001Setting::STATUS_INDEXING,
            'sent',
            ['%count%' => 3],
        ];
    }

    #[DataProvider('runningStatuses')]
    public function testRefreshFailsARunningSynchronizationThatStoppedMakingProgress(string $status): void
    {
        $this->setting->reportSync($status, 'Sent 500 of 1000 products...', 500);
        $stale = new \DateTimeImmutable(sprintf('-%d seconds', Agent001Setting::STALE_AFTER_SECONDS + 60));
        $this->set($this->setting, 'syncUpdatedAt', $stale);

        $this->synchronizer([])->refresh($this->setting);

        $this->assertSame(Agent001Setting::STATUS_FAILED, $this->setting->getSyncStatus());
        $this->assertSame(self::MESSAGE . 'stalled', $this->setting->getSyncMessage());
        $this->assertSame([], $this->requests);
    }

    #[DataProvider('runningStatuses')]
    public function testRefreshKeepsARunningSynchronizationThatMakesProgress(string $status): void
    {
        $this->setting->reportSync($status, 'Sent 500 of 1000 products...', 500);

        $this->synchronizer([])->refresh($this->setting);

        $this->assertSame($status, $this->setting->getSyncStatus());
        $this->assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runningStatuses(): iterable
    {
        yield 'queued' => [Agent001Setting::STATUS_QUEUED];
        yield 'reading' => [Agent001Setting::STATUS_READING];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param list<array<string, string>> $storeContext
     */
    private function synchronizer(
        array $products,
        ?int $count = null,
        bool $claimed = true,
        array $storeContext = self::STORE_CONTEXT,
    ): Synchronizer {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn(self::CHANNEL_CODE);
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn([$channel]);

        $productReader = $this->createMock(ProductReaderInterface::class);
        $productReader->method('count')->willReturn($count ?? count($products));
        $productReader->method('readBatchAfterId')->willReturnCallback(
            static fn (int $afterId, int $limit): array => array_slice(
                array_values(array_filter($products, static fn (array $product): bool => $product['id'] > $afterId)),
                0,
                $limit,
            ),
        );

        $storeContextReader = $this->createMock(StoreContextReaderInterface::class);
        $storeContextReader->method('read')->willReturn($storeContext);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('apiKey')->willReturn('api-key');

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findOneByChannelCode')->willReturn($this->setting);
        $settingRepository->method('claimSync')->willReturnCallback(
            function (Agent001Setting $setting, string $sessionId) use ($claimed): bool {
                if ($claimed) {
                    $setting->reportSync(Agent001Setting::STATUS_READING, self::MESSAGE . 'starting', 0);
                    $this->set($setting, 'syncSessionId', $sessionId);
                }

                return $claimed;
            },
        );

        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $headers = [];
            foreach ($options['headers'] ?? [] as $header) {
                [$name, $value] = explode(': ', (string) $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => is_array($body) ? $body : [],
                'bytes' => strlen((string) ($options['body'] ?? '')),
            ];
            if (null !== $this->onRequest) {
                ($this->onRequest)();
            }

            return array_shift($this->responses) ?? new MockResponse('{}', ['http_code' => 200]);
        });

        return new Synchronizer(
            $productReader,
            $storeContextReader,
            new Agent001Client($http, 'https://backend.test', 'https://backend.test', new NullLogger(), 0),
            $connectionRepository,
            $settingRepository,
            new StoreChannels($channelRepository),
            $this->createMock(EntityManagerInterface::class),
            new ArrayAdapter(),
            $this->logger,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(int $count, int $descriptionBytes = 10): array
    {
        $products = [];
        for ($id = 1; $id <= $count; ++$id) {
            $products[] = [
                'id' => $id,
                'title' => 'Product ' . $id,
                'description' => str_repeat('x', $descriptionBytes),
            ];
        }

        return $products;
    }

    /**
     * @param 'headers'|'body' $part
     *
     * @return list<mixed>
     */
    private function sent(string $part, string $key): array
    {
        return array_column(array_column($this->requests, $part), $key);
    }

    private function set(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}
