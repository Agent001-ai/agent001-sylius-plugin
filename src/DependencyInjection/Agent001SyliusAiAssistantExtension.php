<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;

final class Agent001SyliusAiAssistantExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public const COMMAND_BUS = 'agent001_sylius_ai_assistant.command_bus';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $container->setParameter('agent001_sylius_ai_assistant.base_url', $config['base_url']);
        $container->setParameter('agent001_sylius_ai_assistant.api_base_url', $config['api_base_url']);
        $container->setParameter('agent001_sylius_ai_assistant.allowed_origins', $config['allowed_origins']);

        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrine($container);
        $this->prependDoctrineMigrations($container);
        $this->prependMessenger($container);
        $this->prependPackages($container);
    }

    /**
     * @param array<mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Agent001\SyliusAiAssistantPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@Agent001SyliusAiAssistantPlugin/src/Migrations';
    }

    /**
     * @return list<string>
     */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\\Bundle\\CoreBundle\\Migrations',
        ];
    }

    private function prependDoctrine(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'Agent001SyliusAiAssistantPlugin' => [
                        'type' => 'attribute',
                        'dir' => __DIR__ . '/../Entity',
                        'is_bundle' => false,
                        'prefix' => 'Agent001\SyliusAiAssistantPlugin\Entity',
                        'alias' => 'Agent001SyliusAiAssistantPlugin',
                    ],
                ],
            ],
        ]);
    }

    private function prependPackages(ContainerBuilder $container): void
    {
        $directory = __DIR__ . '/../../config';
        foreach ([$directory . '/config.yaml', ...(glob($directory . '/twig_hooks/*.yaml') ?: [])] as $file) {
            $container->addResource(new FileResource($file));
            $packages = Yaml::parseFile($file);
            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $extension => $config) {
                if (is_string($extension) && is_array($config) && $container->hasExtension($extension)) {
                    $container->prependExtensionConfig($extension, $config);
                }
            }
        }
    }

    private function prependMessenger(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'buses' => [
                    self::COMMAND_BUS => [],
                ],
            ],
        ]);
    }
}
