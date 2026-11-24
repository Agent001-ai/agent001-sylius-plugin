<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Agent;

final class AgentId
{
    private const PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public static function normalize(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $id = strtolower(trim($value));

        return 1 === preg_match(self::PATTERN, $id) ? $id : '';
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public static function normalizeList(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = self::normalize($value);
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
