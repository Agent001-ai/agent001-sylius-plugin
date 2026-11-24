<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Sync;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;

final class NightlySyncPolicy
{
    public const MIN_OFFSET_SECONDS = 3600;

    public const MAX_OFFSET_SECONDS = 18000;

    public static function offsetFor(string $site): int
    {
        return self::MIN_OFFSET_SECONDS + (crc32($site) % (self::MAX_OFFSET_SECONDS - self::MIN_OFFSET_SECONDS));
    }

    public static function isDue(Agent001Setting $setting, \DateTimeImmutable $now): bool
    {
        if (!$setting->hasAgent() || !$setting->isSetupCompleted()) {
            return false;
        }

        $site = (string) $setting->getSite() . '|' . $setting->getChannelCode();
        $slot = $now->setTime(0, 0)->modify(sprintf('+%d seconds', self::offsetFor($site)));
        if ($now < $slot) {
            return false;
        }

        $last = $setting->getLastSyncAt();

        return null === $last || $last < $slot;
    }
}
