<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\DependencyInjection;

use Agent001\SyliusAiAssistantPlugin\Client\BackendUrl;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('agent001_sylius_ai_assistant');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $children = $rootNode->children();
        $children->scalarNode('base_url')
            ->info('Public Agent001 address for the connect flow, links and the widget loader.')
            ->defaultValue('https://app.agent001.ai')
            ->validate()
            ->ifTrue(static fn (mixed $url): bool => !self::isAllowed($url))
            ->thenInvalid('The Agent001 base URL must use https (http only for localhost), got %s.');
        $children->scalarNode('api_base_url')
            ->info('Agent001 address used for server-to-server API calls. Defaults to base_url.')
            ->defaultNull()
            ->validate()
            ->ifTrue(static fn (mixed $url): bool => null !== $url && !self::isAllowed($url))
            ->thenInvalid('The Agent001 API base URL must use https (http only for localhost), got %s.');
        $children->arrayNode('allowed_origins')
            ->defaultValue([])
            ->scalarPrototype();

        return $treeBuilder;
    }

    private static function isAllowed(mixed $url): bool
    {
        return is_string($url) && (empty($url) || BackendUrl::isAllowed($url));
    }
}
