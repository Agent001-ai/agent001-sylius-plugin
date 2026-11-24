<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Sync;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;

final class Synchronizer
{
    public const DEFAULT_BATCH_SIZE = 500;

    public const MAX_PRODUCTS = 50000;

    public const MAX_BATCHES = 1000;

    public const CHECK_INTERVAL_SECONDS = 5;

    public const INDEXING_TIMEOUT_SECONDS = 7200;

    private const CHECK_PREFIX = 'agent001_sylius_ai_assistant_sync_checked_';

    private const MESSAGE = 'agent001_sylius_ai_assistant.sync.';

    public function __construct(
        private readonly ProductReaderInterface $productReader,
        private readonly StoreContextReaderInterface $storeContextReader,
        private readonly Agent001ClientInterface $client,
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly StoreChannels $channels,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{success: bool, message: string, parameters: array<string, int|string>, count: int}
     */
    public function sync(string $channelCode): array
    {
        $setting = $this->settingRepository->findOneByChannelCode($channelCode);
        if (null === $setting || !$setting->hasAgent()) {
            return $this->result(false, 'no_agent');
        }

        $apiKey = $this->connectionRepository->apiKey();
        if ('' === $apiKey) {
            return $this->fail($setting, 'not_connected');
        }

        $channel = $this->channels->find($channelCode);
        if (null === $channel) {
            return $this->fail($setting, 'channel_disabled');
        }

        $site = (string) $setting->getSite();
        if ('' === $site) {
            return $this->fail($setting, 'site_unknown');
        }

        $sessionId = bin2hex(random_bytes(16));
        if (!$this->settingRepository->claimSync($setting, $sessionId, new \DateTimeImmutable())) {
            return $this->result(false, 'already_running');
        }

        $sent = 0;

        try {
            return $this->run($setting, $channel, $apiKey, $site, $sent);
        } catch (\Throwable $e) {
            $this->logger->error('Agent001 catalog synchronization failed.', [
                'exception' => $e,
                'channel' => $channelCode,
            ]);
            $setting = $this->owned($channelCode, $sessionId);

            return null === $setting
                ? $this->result(false, 'replaced', count: $sent)
                : $this->fail($setting, 'failed', ['%error%' => $e->getMessage()], $sent);
        }
    }

    public function isReading(Agent001Setting $setting): bool
    {
        $this->refresh($setting);

        return Agent001Setting::STATUS_READING === $setting->getSyncStatus();
    }

    public function refresh(Agent001Setting $setting): void
    {
        $status = $setting->getSyncStatus();
        if (Agent001Setting::STATUS_QUEUED === $status || Agent001Setting::STATUS_READING === $status) {
            if ($setting->isSyncStale(new \DateTimeImmutable())) {
                $this->conclude($setting, false, 'stalled');
            }

            return;
        }

        if (Agent001Setting::STATUS_INDEXING !== $status) {
            return;
        }

        $apiKey = $this->connectionRepository->apiKey();
        $sessionId = (string) $setting->getSyncSessionId();
        if ('' === $sessionId || '' === $apiKey) {
            $this->conclude($setting, false, 'state_lost');

            return;
        }

        $checked = $this->cache->getItem(self::CHECK_PREFIX . $this->key($setting->getChannelCode()));
        if ($checked->isHit()) {
            return;
        }
        $checked->set(1);
        $checked->expiresAfter(self::CHECK_INTERVAL_SECONDS);
        $this->cache->save($checked);

        $result = $this->client->fetchSyncSession($apiKey, $setting->getCatalogChannel(), $sessionId);
        $status = $result['status'];
        $remoteState = $result['data']['status'] ?? null;
        $state = $status >= 200 && $status < 300 && is_string($remoteState) ? $remoteState : '';

        if (404 === $status) {
            $this->conclude($setting, false, 'replaced');
        } elseif ($status >= 400 && $status < 500 && 408 !== $status && 429 !== $status) {
            $this->conclude($setting, false, 'indexing_failed', ['%error%' => '']);
        } elseif ('succeeded' === $state) {
            $this->conclude($setting, true, 'synced', ['%count%' => $setting->getSyncCount()]);
        } elseif ('failed' === $state) {
            $error = is_string($result['data']['error'] ?? null) ? trim($result['data']['error']) : '';
            $this->conclude($setting, false, 'indexing_failed', ['%error%' => $error]);
        } elseif (time() - (int) $setting->getSyncUpdatedAt()?->getTimestamp() > self::INDEXING_TIMEOUT_SECONDS) {
            $this->conclude($setting, false, 'indexing_timeout');
        }
    }

    /**
     * @return array{success: bool, message: string, parameters: array<string, int|string>, count: int}
     */
    private function run(
        Agent001Setting $setting,
        ChannelInterface $channel,
        string $apiKey,
        string $site,
        int &$sent,
    ): array {
        $channelCode = $setting->getChannelCode();
        $catalog = $setting->getCatalogChannel();
        $sessionId = (string) $setting->getSyncSessionId();
        $taxonCodes = $setting->getTaxonCodes();
        $total = $this->productReader->count($channel, $taxonCodes);
        if ($total > self::MAX_PRODUCTS) {
            return $this->fail($setting, 'too_many_products');
        }

        $storeContext = $this->storeContextReader->read($channel, $site);
        $afterId = 0;
        $batchIdx = 0;

        do {
            $batch = $this->productReader->readBatchAfterId(
                $afterId,
                self::DEFAULT_BATCH_SIZE + 1,
                $channel,
                $site,
                $taxonCodes,
            );
            $isLast = count($batch) <= self::DEFAULT_BATCH_SIZE;
            $batch = array_slice($batch, 0, self::DEFAULT_BATCH_SIZE);

            if ($sent + count($batch) > self::MAX_PRODUCTS || (!$isLast && $batchIdx + 1 >= self::MAX_BATCHES)) {
                return $this->fail($setting, 'catalog_grew', count: $sent);
            }

            if ([] !== $batch) {
                $nextId = $this->productId(end($batch));
                if ($nextId <= $afterId) {
                    return $this->fail($setting, 'read_stuck', count: $sent);
                }
                $afterId = $nextId;
            }

            $error = $this->postBatch(
                $apiKey,
                $catalog,
                $site,
                $sessionId,
                $batchIdx,
                $isLast,
                $batch,
                0 === $batchIdx ? $storeContext : [],
            );
            if (null !== $error) {
                return $this->fail($setting, $error[0], $error[1], $sent);
            }

            $sent += count($batch);
            $this->entityManager->clear();
            $setting = $this->owned($channelCode, $sessionId);
            $channel = $this->channels->find($channelCode);
            if (null === $setting || null === $channel) {
                return $this->result(false, 'replaced', count: $sent);
            }

            $setting->reportSync(
                Agent001Setting::STATUS_READING,
                self::MESSAGE . 'progress',
                $sent,
                ['%sent%' => $sent, '%total%' => $total],
            );
            $this->entityManager->flush();
        } while (!$isLast);

        $setting->reportSync(Agent001Setting::STATUS_INDEXING, self::MESSAGE . 'sent', $sent, ['%count%' => $sent]);
        $setting->setLastSyncAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->result(true, 'sent', ['%count%' => $sent], $sent);
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @param list<array<string, string>> $storeContext
     *
     * @return array{string, array<string, int|string>}|null
     */
    private function postBatch(
        string $apiKey,
        string $channel,
        string $site,
        string $sessionId,
        int &$batchIdx,
        bool $isLast,
        array $batch,
        array $storeContext,
    ): ?array {
        $result = $this->client->postProductBatch(
            $apiKey,
            $channel,
            $site,
            $sessionId,
            $batchIdx,
            $isLast,
            $batch,
            $storeContext,
        );

        if (Agent001ClientInterface::BATCH_TOO_LARGE === $result['error']) {
            if (count($batch) <= 1) {
                return ['product_too_large', ['%id%' => $this->productId($batch[0] ?? [])]];
            }

            $half = intdiv(count($batch), 2);
            $error = $this->postBatch(
                $apiKey,
                $channel,
                $site,
                $sessionId,
                $batchIdx,
                false,
                array_slice($batch, 0, $half),
                $storeContext,
            );

            return $error ?? $this->postBatch(
                $apiKey,
                $channel,
                $site,
                $sessionId,
                $batchIdx,
                $isLast,
                array_slice($batch, $half),
                [],
            );
        }

        if (!$result['success']) {
            return ['batch_failed', ['%batch%' => $batchIdx, '%error%' => $result['error']]];
        }

        ++$batchIdx;

        return null;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function conclude(Agent001Setting $setting, bool $success, string $message, array $parameters = []): void
    {
        $setting->finishSync($success, self::MESSAGE . $message, new \DateTimeImmutable(), $parameters);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return array{success: bool, message: string, parameters: array<string, int|string>, count: int}
     */
    private function fail(Agent001Setting $setting, string $message, array $parameters = [], int $count = 0): array
    {
        $setting->reportSync(Agent001Setting::STATUS_FAILED, self::MESSAGE . $message, $count, $parameters);
        $this->conclude($setting, false, $message, $parameters);

        return $this->result(false, $message, $parameters, $count);
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return array{success: bool, message: string, parameters: array<string, int|string>, count: int}
     */
    private function result(bool $success, string $message, array $parameters = [], int $count = 0): array
    {
        return [
            'success' => $success,
            'message' => self::MESSAGE . $message,
            'parameters' => $parameters,
            'count' => $count,
        ];
    }

    private function owned(string $channelCode, string $sessionId): ?Agent001Setting
    {
        $setting = $this->settingRepository->findOneByChannelCode($channelCode);

        return null !== $setting && $sessionId === $setting->getSyncSessionId() ? $setting : null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function productId(array $product): int
    {
        return is_int($product['id'] ?? null) ? $product['id'] : 0;
    }

    private function key(string $channelCode): string
    {
        return hash('sha256', $channelCode);
    }
}
