<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class Agent001TwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('agent001_sylius_ai_assistant_widget', [Agent001TwigRuntime::class, 'widget'])];
    }
}
