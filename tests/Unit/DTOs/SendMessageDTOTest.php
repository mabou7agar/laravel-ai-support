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
}
