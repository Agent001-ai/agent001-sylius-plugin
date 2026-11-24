<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Entity;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use PHPUnit\Framework\TestCase;

final class Agent001SettingTest extends TestCase
{
    private const AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    public function testItScopesCatalogToChannelAndGeneratesWidgetKey(): void
    {
        $setting = new Agent001Setting('FASHION_WEB');

        $this->assertSame('channel:FASHION_WEB', $setting->getCatalogChannel());
        $this->assertSame(64, strlen($setting->getWidgetKey()));
        $this->assertNotSame($setting->getWidgetKey(), (new Agent001Setting('FASHION_WEB'))->getWidgetKey());
    }

    public function testWidgetIsVisibleOnlyAfterFirstSuccessfulSync(): void
    {
        $setting = new Agent001Setting('WEB');
        $setting->assignAgent(strtoupper(self::AGENT), 'token', true, 'https://shop.test');

        $this->assertSame(self::AGENT, $setting->getAgentId());
        $this->assertFalse($setting->isWidgetVisible());

        $setting->finishSync(true, 'Synced.', new \DateTimeImmutable());
        $this->assertTrue($setting->isWidgetVisible());

        $setting->setAgentActive(false);
        $this->assertFalse($setting->isWidgetVisible());

        $setting->assignAgent(self::AGENT, 'token', true, 'https://shop.test');
        $this->assertFalse($setting->isSetupCompleted());
    }

    public function testRunningSyncBecomesStaleWithoutProgress(): void
    {
        $setting = new Agent001Setting('WEB');
        $setting->queueSync();

        $this->assertTrue($setting->isSyncRunning());
        $this->assertFalse($setting->isSyncStale(new \DateTimeImmutable('+10 minutes')));
        $this->assertTrue($setting->isSyncStale(new \DateTimeImmutable('+31 minutes')));

        $setting->reportSync(Agent001Setting::STATUS_READING, 'Sent 500 of 900 products...', 500);
        $this->assertFalse($setting->isSyncStale(new \DateTimeImmutable('+29 minutes')));
    }

    public function testItFiltersTaxonCodes(): void
    {
        $setting = new Agent001Setting('WEB');
        $setting->setTaxonCodes(['shoes', 't-shirts', '', 'shoes', 7]);

        $this->assertSame(['shoes', 't-shirts'], $setting->getTaxonCodes());
    }
}
