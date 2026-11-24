<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Symfony\Component\RateLimiter\RateLimiterFactory;

final class OrderLookupThrottle
{
    public function __construct(
        private readonly RateLimiterFactory $anonymousApiLimiter,
        private readonly RateLimiterFactory $failureLimiter,
        private readonly RateLimiterFactory $emailFailureLimiter,
    ) {
    }

    public function acceptRequest(string $ip): bool
    {
        return $this->anonymousApiLimiter->create($ip)->consume()->isAccepted();
    }

    public function isBlocked(string $scope, string $ip, string $email, string $number): bool
    {
        foreach ($this->failureLimits($scope, $ip, $email, $number) as [$limiter, $key]) {
            if (0 === $limiter->create($key)->consume(0)->getRemainingTokens()) {
                return true;
            }
        }

        return false;
    }

    public function recordFailure(string $scope, string $ip, string $email, string $number): void
    {
        foreach ($this->failureLimits($scope, $ip, $email, $number) as [$limiter, $key]) {
            $limiter->create($key)->consume();
        }
    }

    /**
     * @return list<array{RateLimiterFactory, string}>
     */
    private function failureLimits(string $scope, string $ip, string $email, string $number): array
    {
        return [
            [$this->failureLimiter, 'email|' . $scope . '|' . $ip . '|' . mb_strtolower($email)],
            [$this->failureLimiter, 'number|' . $scope . '|' . $ip . '|' . mb_strtolower($number)],
            [$this->emailFailureLimiter, $scope . '|' . mb_strtolower($email)],
        ];
    }
}
