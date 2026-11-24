<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Sync;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\CheckSyncSessionMessage;
use Agent001\SyliusAiAssistantPlugin\Sync\IndexingWatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

final class IndexingWatcherTest extends TestCase
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    public function testAnIndexingSessionIsCheckedAgainAfterTheCheckInterval(): void
    {
        $this->watcher([new InMemoryTransport()])->watch($this->setting(Agent001Setting::STATUS_INDEXING));

        $this->assertCount(1, $this->dispatched);
        $this->assertEquals(new CheckSyncSessionMessage('WEB', 'session'), $this->dispatched[0]->getMessage());
        $this->assertSame(5000, $this->dispatched[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testAFinishedSynchronizationIsNotChecked(): void
    {
        $watcher = $this->watcher([new InMemoryTransport()]);

        $watcher->watch($this->setting(Agent001Setting::STATUS_SUCCESS));
        $watcher->watch($this->setting(Agent001Setting::STATUS_FAILED));

        $this->assertSame([], $this->dispatched);
    }

    public function testChecksAreNotHandledSynchronously(): void
    {
        $this->watcher([])->watch($this->setting(Agent001Setting::STATUS_INDEXING));
        $this->watcher([$this->createMock(SyncTransport::class)])
            ->watch($this->setting(Agent001Setting::STATUS_INDEXING));

        $this->assertSame([], $this->dispatched);
    }

    /**
     * @param list<SenderInterface> $senders
     */
    private function watcher(array $senders): IndexingWatcher
    {
        $sendersLocator = $this->createMock(SendersLocatorInterface::class);
        $sendersLocator->method('getSenders')->willReturn($senders);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            self::assertInstanceOf(Envelope::class, $message);
            $this->dispatched[] = $message;

            return $message;
        });

        return new IndexingWatcher($bus, $sendersLocator);
    }

    private function setting(string $status): Agent001Setting
    {
        $setting = new Agent001Setting('WEB');
        $setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, 'https://shop.test');
        $setting->reportSync($status, 'agent001_sylius_ai_assistant.sync.sent', 1);
        (new \ReflectionProperty(Agent001Setting::class, 'syncSessionId'))->setValue($setting, 'session');

        return $setting;
    }
}
