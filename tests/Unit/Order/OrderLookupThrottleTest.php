<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Order;

use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

final class OrderLookupThrottleTest extends TestCase
{
    public function testWidgetRequestsAreLimitedPerIpAcrossChannels(): void
    {
        $throttle = new OrderLookupThrottle(
            anonymousApiLimiter: $this->limiter('api', 2),
            failureLimiter: $this->limiter('failures', 5),
            emailFailureLimiter: $this->limiter('email_failures', 50),
        );

        $this->assertTrue($throttle->acceptRequest('203.0.113.1'));
        $this->assertTrue($throttle->acceptRequest('203.0.113.1'));
        $this->assertFalse($throttle->acceptRequest('203.0.113.1'));
        $this->assertTrue($throttle->acceptRequest('203.0.113.2'));
    }

    public function testTheConfiguredWidgetLimitIsTenRequestsPerMinute(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/config.yaml');

        $this->assertSame(
            ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 minute', 'lock_factory' => null],
            $config['framework']['rate_limiter']['agent001_sylius_ai_assistant_api'] ?? null,
        );
    }

    private function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }
}
