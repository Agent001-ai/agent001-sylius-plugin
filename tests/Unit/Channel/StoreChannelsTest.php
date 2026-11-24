<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Channel;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\HttpFoundation\Request;

final class StoreChannelsTest extends TestCase
{
    public function testEnabledChannelsAreIndexedByCode(): void
    {
        $channels = $this->channels(['WEB' => 'shop.test', 'B2B' => null]);

        $this->assertSame(['WEB', 'B2B'], array_keys($channels->enabled()));
        $this->assertSame('B2B', $channels->find('B2B')?->getCode());
        $this->assertNull($channels->find('MISSING'));
    }

    public function testSiteUsesTheChannelHostnameWithTheRequestScheme(): void
    {
        $channels = $this->channels([]);
        $request = Request::create('https://admin.shop.test:8443/admin');

        $this->assertSame('https://admin.shop.test:8443', $channels->site($this->channel('WEB', null), $request));
        $this->assertSame(
            'https://admin.shop.test:8443',
            $channels->site($this->channel('WEB', ' Admin.Shop.Test '), $request),
        );
        $this->assertSame('https://de.shop.test', $channels->site($this->channel('DE', 'DE.shop.test'), $request));
    }

    public function testHostsAreValidUniqueLowercaseHostnames(): void
    {
        $channels = $this->channels([
            'WEB' => 'Shop.Test',
            'COPY' => 'shop.test',
            'DE' => 'de.shop.test',
            'LOCAL' => 'localhost',
            'BAD' => 'bad host.test',
            'NONE' => null,
        ]);

        $this->assertSame(['shop.test', 'de.shop.test'], $channels->hosts());
    }

    public function testHostsAreLimitedToFifty(): void
    {
        $hostnames = [];
        for ($i = 1; $i <= 60; ++$i) {
            $hostnames['C' . $i] = sprintf('shop%d.test', $i);
        }

        $hosts = $this->channels($hostnames)->hosts();

        $this->assertCount(50, $hosts);
        $this->assertSame('shop1.test', $hosts[0]);
    }

    #[DataProvider('origins')]
    public function testOrigin(string $url, string $origin): void
    {
        $this->assertSame($origin, StoreChannels::origin($url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function origins(): iterable
    {
        yield 'path and query are dropped' => ['HTTPS://Shop.Test/admin?x=1', 'https://shop.test'];
        yield 'default https port' => ['https://shop.test:443/', 'https://shop.test'];
        yield 'default http port' => ['http://shop.test:80', 'http://shop.test'];
        yield 'custom port' => ['https://shop.test:8443/path', 'https://shop.test:8443'];
        yield 'no scheme' => ['shop.test/admin', ''];
        yield 'invalid' => ['http://', ''];
    }

    /**
     * @param array<string, string|null> $hostnames
     */
    private function channels(array $hostnames): StoreChannels
    {
        $channels = [];
        foreach ($hostnames as $code => $hostname) {
            $channels[] = $this->channel($code, $hostname);
        }

        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findBy')->with(['enabled' => true], ['id' => 'ASC'])->willReturn($channels);

        return new StoreChannels($repository);
    }

    private function channel(string $code, ?string $hostname): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);
        $channel->setHostname($hostname);

        return $channel;
    }
}
