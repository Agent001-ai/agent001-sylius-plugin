<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Exception;

final class Agent001Exception extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(string $translationKey, public readonly array $parameters = [])
    {
        parent::__construct($translationKey);
    }
}
