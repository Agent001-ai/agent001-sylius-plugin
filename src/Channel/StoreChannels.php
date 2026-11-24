<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Channel;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

final class StoreChannels
{
    private const HOST_PATTERN = '/\A(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    private const MAX_HOSTS = 50;

    /**
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(private readonly ChannelRepositoryInterface $channelRepository)
    {
    }

    /**
     * @return array<string, ChannelInterface>
     */
    public function enabled(): array
    {
        $channels = [];
        foreach ($this->channelRepository->findBy(['enabled' => true], ['id' => 'ASC']) as $channel) {
            if ($channel instanceof ChannelInterface && null !== $channel->getCode()) {
                $channels[$channel->getCode()] = $channel;
            }
        }

        return $channels;
    }

    public function find(string $code): ?ChannelInterface
    {
        return $this->enabled()[$code] ?? null;
    }

    public function site(ChannelInterface $channel, Request $request): string
    {
        $hostname = strtolower(trim((string) $channel->getHostname()));
        if ('' === $hostname || $hostname === strtolower($request->getHost())) {
            return $request->getSchemeAndHttpHost();
        }

        return $request->getScheme() . '://' . $hostname;
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = [];
        foreach ($this->enabled() as $channel) {
            $host = strtolower(trim((string) $channel->getHostname()));
            if (1 === preg_match(self::HOST_PATTERN, $host)) {
                $hosts[$host] = true;
            }
        }

        return array_slice(array_keys($hosts), 0, self::MAX_HOSTS);
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = ('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port);

        return $scheme . '://' . strtolower($parts['host']) . (null === $port || $default ? '' : ':' . $port);
    }
}
