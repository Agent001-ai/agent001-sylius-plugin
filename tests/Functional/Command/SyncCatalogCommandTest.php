<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Command;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Sync\NightlySyncPolicy;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;

final class SyncCatalogCommandTest extends FunctionalTestCase
{
    private const OTHER_CHANNEL = self::CHANNEL_CODE . '_B2B';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->purge();
        $this->createChannel();
        $this->createChannel(self::OTHER_CHANNEL);
    }

    public function testItFailsWhenTheStoreIsNotConnected(): void
    {
        $this->createSetting();

        $tester = $this->sync();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('not connected', $tester->getDisplay());
        $this->assertSame([], $this->queuedMessages());
    }

    public function testItQueuesEveryChannelWithAnAgent(): void
    {
        $this->connectStore();
        $this->createSetting();
        $this->createSetting(self::OTHER_CHANNEL, self::OTHER_AGENT_ID);

        $tester = $this->sync();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertEquals(
            [new SyncCatalogMessage(self::CHANNEL_CODE), new SyncCatalogMessage(self::OTHER_CHANNEL)],
            $this->queuedMessages(),
        );
        $this->assertSame(Agent001Setting::STATUS_QUEUED, $this->setting()?->getSyncStatus());
        $this->assertSame(Agent001Setting::STATUS_QUEUED, $this->setting(self::OTHER_CHANNEL)?->getSyncStatus());
    }

    public function testItQueuesOnlyTheRequestedChannel(): void
    {
        $this->connectStore();
        $this->createSetting();
        $this->createSetting(self::OTHER_CHANNEL, self::OTHER_AGENT_ID);

        $this->sync(['--channel' => self::OTHER_CHANNEL]);

        $this->assertEquals([new SyncCatalogMessage(self::OTHER_CHANNEL)], $this->queuedMessages());
        $this->assertNull($this->setting()?->getSyncStatus());
    }

    public function testItDoesNotQueueAChannelThatIsAlreadySynchronizing(): void
    {
        $this->connectStore();
        $this->createSetting()->queueSync();
        $this->entityManager()->flush();

        $tester = $this->sync();

        $this->assertStringContainsString('already synchronizing', $tester->getDisplay());
        $this->assertSame([], $this->queuedMessages());
    }

    public function testDueQueuesOnlyChannelsWhoseNightlyWindowHasPassedSinceTheLastSynchronization(): void
    {
        $this->connectStore();
        $synced = $this->createSetting();
        $synced->setLastSyncAt(new \DateTimeImmutable());
        $stale = $this->createSetting(self::OTHER_CHANNEL, self::OTHER_AGENT_ID);
        $stale->setLastSyncAt(new \DateTimeImmutable('-2 days'));
        $this->entityManager()->flush();
        $expected = NightlySyncPolicy::isDue($stale, new \DateTimeImmutable())
            ? [new SyncCatalogMessage(self::OTHER_CHANNEL)]
            : [];

        $tester = $this->sync(['--due' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertEquals($expected, $this->queuedMessages());
        $this->assertNull($this->setting()?->getSyncStatus());
    }

    public function testDueSkipsChannelsBeforeTheirFirstSynchronization(): void
    {
        $this->connectStore();
        $this->createSetting(setupCompleted: false);

        $tester = $this->sync(['--due' => true]);

        $this->assertStringContainsString('Nightly synchronization is not due.', $tester->getDisplay());
        $this->assertSame([], $this->queuedMessages());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function sync(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('agent001:sync'));
        $tester->execute($input);

        return $tester;
    }
}
