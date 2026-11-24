<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\DependencyInjection;

use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Agent001SyliusAiAssistantExtension;
use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    private const BASE_URL = 'agent001_sylius_ai_assistant.base_url';

    private const API_BASE_URL = 'agent001_sylius_ai_assistant.api_base_url';

    public function testTheApiAddressIsOptional(): void
    {
        $container = $this->load([]);

        $this->assertSame('https://app.agent001.ai', $container->getParameter(self::BASE_URL));
        $this->assertNull($container->getParameter(self::API_BASE_URL));
    }

    public function testAnExplicitlyEmptyApiAddressFallsBackToTheBaseAddress(): void
    {
        $container = $this->load(['base_url' => 'https://agent001.test', 'api_base_url' => null]);

        $this->assertSame('https://agent001.test', $container->getParameter(self::BASE_URL));
        $this->assertNull($container->getParameter(self::API_BASE_URL));
    }

    public function testBothAddressesCanBeConfigured(): void
    {
        $container = $this->load([
            'base_url' => 'http://agent001.localhost',
            'api_base_url' => 'https://internal.test',
        ]);

        $this->assertSame('http://agent001.localhost', $container->getParameter(self::BASE_URL));
        $this->assertSame('https://internal.test', $container->getParameter(self::API_BASE_URL));
    }

    #[DataProvider('allowedUrls')]
    public function testSecureOrLocalAddressesAreAccepted(string $url): void
    {
        $config = $this->process(['base_url' => $url, 'api_base_url' => $url]);

        $this->assertSame($url, $config['base_url']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrls(): iterable
    {
        yield 'https' => ['https://app.agent001.ai'];
        yield 'localhost' => ['http://localhost:8000'];
        yield 'loopback' => ['http://127.0.0.1'];
        yield 'localhost subdomain' => ['http://agent001.localhost'];
    }

    #[DataProvider('rejectedUrls')]
    public function testInsecureOrInvalidAddressesAreRejected(string $option, string $url): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([$option => $url]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'plain http' => ['base_url', 'http://example.com'];
        yield 'localhost lookalike' => ['base_url', 'http://localhost.example.com'];
        yield 'no scheme' => ['base_url', 'app.agent001.ai'];
        yield 'other scheme' => ['base_url', 'ftp://localhost'];
        yield 'plain http api' => ['api_base_url', 'http://example.com'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new Agent001SyliusAiAssistantExtension())->load([$config], $container);

        return $container;
    }
}
