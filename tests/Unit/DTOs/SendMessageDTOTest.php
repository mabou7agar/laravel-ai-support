<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\SendMessageDTO;
use LaravelAIEngine\Tests\UnitTestCase;

class SendMessageDTOTest extends UnitTestCase
{
    public function test_agent_options_preserve_explicit_false_response_suggestions(): void
    {
        $dto = new SendMessageDTO(
            message: 'hello',
            sessionId: 'session',
            responsePointsFormat: 'array',
            responseSuggestions: false,
            responseSuggestionLimit: 3
        );

        $this->assertSame([
            'response_points_format' => 'array',
            'response_suggestion_limit' => 3,
            'response_suggestions' => false,
        ], $dto->agentOptions());
    }

    public function test_agent_options_forward_force_rag_when_enabled(): void
    {
        $dto = new SendMessageDTO(
            message: 'search docs',
            sessionId: 'session',
            forceRag: true
        );

        $this->assertTrue($dto->agentOptions()['force_rag']);
    }

    public function test_agent_options_forward_execution_mode_when_present(): void
    {
        $dto = new SendMessageDTO(
            message: 'hello',
            sessionId: 'session',
            executionMode: 'auto'
        );

        $this->assertSame('auto', $dto->toArray()['execution_mode']);
        $this->assertSame('auto', $dto->agentOptions()['execution_mode']);
    }

}
