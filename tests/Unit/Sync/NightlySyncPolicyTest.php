<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Sync;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Sync\NightlySyncPolicy;
use PHPUnit\Framework\TestCase;

final class NightlySyncPolicyTest extends TestCase
{
    private const AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    public function testOffsetStaysInsideTheNightWindow(): void
    {
        foreach (['https://a.test|WEB', 'https://b.test|MOBILE', '', 'https://shop.example.org|WEB'] as $site) {
            $offset = NightlySyncPolicy::offsetFor($site);

            $this->assertGreaterThanOrEqual(3600, $offset);
            $this->assertLessThan(18000, $offset);
        }
    }

    public function testItIsNeverDueBeforeTheFirstSync(): void
    {
        $now = new \DateTimeImmutable('2026-10-23 23:00:00');
        $setting = new Agent001Setting('WEB');

        $this->assertFalse(NightlySyncPolicy::isDue($setting, $now));

        $setting->assignAgent(self::AGENT, 'token', true, 'https://shop.test');
        $this->assertFalse(NightlySyncPolicy::isDue($setting, $now));
    }

    public function testItIsDueOnceAfterTheChannelSlot(): void
    {
        $setting = new Agent001Setting('WEB');
        $setting->assignAgent(self::AGENT, 'token', true, 'https://shop.test');
        $setting->setSetupCompleted(true);

        $midnight = new \DateTimeImmutable('2026-10-23 00:00:00');
        $slot = $midnight->modify(sprintf('+%d seconds', NightlySyncPolicy::offsetFor('https://shop.test|WEB')));

        $this->assertFalse(NightlySyncPolicy::isDue($setting, $slot->modify('-1 minute')));
        $this->assertTrue(NightlySyncPolicy::isDue($setting, $slot->modify('+1 minute')));

        $setting->setLastSyncAt($slot->modify('+2 minutes'));
        $this->assertFalse(NightlySyncPolicy::isDue($setting, $slot->modify('+3 hours')));
        $this->assertTrue(NightlySyncPolicy::isDue($setting, $slot->modify('+1 day +1 minute')));
    }
}
