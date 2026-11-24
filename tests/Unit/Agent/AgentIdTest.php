<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Agent;

use Agent001\SyliusAiAssistantPlugin\Agent\AgentId;
use PHPUnit\Framework\TestCase;

final class AgentIdTest extends TestCase
{
    public function testItNormalizesUuidsAndRejectsEverythingElse(): void
    {
        $this->assertSame(
            '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f',
            AgentId::normalize(' 0198A6C2-1B3E-7C11-9D2A-3F4B5C6D7E8F '),
        );
        $this->assertSame('', AgentId::normalize(42));
        $this->assertSame('', AgentId::normalize('42'));
        $this->assertSame('', AgentId::normalize(null));
    }

    public function testItDeduplicatesLists(): void
    {
        $id = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

        $this->assertSame([$id], AgentId::normalizeList([$id, strtoupper($id), 'x', 1]));
    }
}
