<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Client;

final class BackendUrl
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host']);
        if ('https' === $scheme) {
            return true;
        }

        return 'http' === $scheme && (in_array($host, self::LOCAL_HOSTS, true) || str_ends_with($host, '.localhost'));
    }
}
