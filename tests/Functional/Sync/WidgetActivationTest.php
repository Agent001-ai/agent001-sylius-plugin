<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Sync;

use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Agent001SyliusAiAssistantExtension;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\CheckSyncSessionMessage;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;

final class WidgetActivationTest extends FunctionalTestCase
{
    private const SITE = 'https://agent001-ft.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->purge();

        $this->clearCheckCache();
    }

    public function testTheWidgetAppearsOnceTheBackendFinishedIndexingWithoutAnAdminVisit(): void
    {
        $this->createChannel();
        $this->connectStore(self::SITE);
        $setting = new Agent001Setting(self::CHANNEL_CODE);
        $setting->assignAgent(self::AGENT_ID, 'embed-token', true, self::SITE);
        $setting->queueSync();
        $this->entityManager()->persist($setting);
        $this->entityManager()->flush();

        $bus = self::getContainer()->get(Agent001SyliusAiAssistantExtension::COMMAND_BUS);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new SyncCatalogMessage(self::CHANNEL_CODE));

        $this->consume();
        $setting = $this->setting();
        self::assertNotNull($setting);
        $this->assertSame(Agent001Setting::STATUS_INDEXING, $setting->getSyncStatus());
        $this->assertFalse($setting->isWidgetVisible());

        $sessionId = (string) $setting->getSyncSessionId();
        $session = '/api/v1/ingest/sessions/' . $sessionId;
        $this->backend()->respond('GET', $session, 200, ['status' => 'succeeded']);

        $this->consume();
        $this->assertNotContains('GET ' . $session, $this->backend()->calls());

        sleep(Synchronizer::CHECK_INTERVAL_SECONDS);
        $this->consume();

        $this->assertContains('GET ' . $session, $this->backend()->calls());
        $setting = $this->setting();
        self::assertNotNull($setting);
        $this->assertSame(Agent001Setting::STATUS_SUCCESS, $setting->getSyncStatus());
        $this->assertTrue($setting->isWidgetVisible());

        $checks = array_filter(
            $this->queuedMessages(),
            static fn (object $message): bool => $message instanceof CheckSyncSessionMessage,
        );
        $this->assertEquals([new CheckSyncSessionMessage(self::CHANNEL_CODE, $sessionId)], [...$checks]);
    }

    public function testTheIndexingSessionIsCheckedAgainUntilItSucceeds(): void
    {
        $this->createChannel();
        $this->connectStore(self::SITE);
        $setting = new Agent001Setting(self::CHANNEL_CODE);
        $setting->assignAgent(self::AGENT_ID, 'embed-token', true, self::SITE);
        $setting->queueSync();
        $this->entityManager()->persist($setting);
        $this->entityManager()->flush();

        $bus = self::getContainer()->get(Agent001SyliusAiAssistantExtension::COMMAND_BUS);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new SyncCatalogMessage(self::CHANNEL_CODE));
        $this->consume();

        $sessionId = (string) $this->setting()?->getSyncSessionId();
        $session = '/api/v1/ingest/sessions/' . $sessionId;
        $this->backend()->respond('GET', $session, 200, ['status' => 'processing']);

        sleep(Synchronizer::CHECK_INTERVAL_SECONDS);
        $this->consume();
        $this->assertSame(Agent001Setting::STATUS_INDEXING, $this->setting()?->getSyncStatus());

        $this->backend()->respond('GET', $session, 200, ['status' => 'succeeded']);
        $this->clearCheckCache();
        sleep(Synchronizer::CHECK_INTERVAL_SECONDS);
        $this->consume();

        $setting = $this->setting();
        self::assertNotNull($setting);
        $this->assertSame(Agent001Setting::STATUS_SUCCESS, $setting->getSyncStatus());
        $this->assertTrue($setting->isWidgetVisible());
        $this->assertCount(2, array_keys($this->backend()->calls(), 'GET ' . $session, true));
        $checks = array_filter(
            $this->queuedMessages(),
            static fn (object $message): bool => $message instanceof CheckSyncSessionMessage,
        );
        $this->assertCount(2, $checks);
    }

    private function clearCheckCache(): void
    {
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    private function consume(): void
    {
        $worker = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $worker->execute([
            'receivers' => ['agent001_sylius_ai_assistant_test'],
            '--limit' => 1,
            '--time-limit' => 1,
            '--no-reset' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $worker->getStatusCode());
    }
}
