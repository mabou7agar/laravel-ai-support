<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentSkillDefinition;

interface AgentSkillProvider
{
    /**
     * @return iterable<int, AgentSkillDefinition|array<string, mixed>>
     */
    public function skills(): iterable;
}
