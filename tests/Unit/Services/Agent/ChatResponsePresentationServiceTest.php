<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Agent\AgentResponseSuggestionService;
use LaravelAIEngine\Services\Agent\ChatResponsePresentationService;
use LaravelAIEngine\Services\Agent\ResponsePointExtractor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ChatResponsePresentationServiceTest extends UnitTestCase
{
    public function test_array_format_returns_points_and_removes_point_lines_from_display_text(): void
    {
        $suggestions = Mockery::mock(AgentResponseSuggestionService::class);
        $suggestions->shouldReceive('suggest')->once()->andReturn([
            ['type' => 'action', 'id' => 'create_invoice', 'label' => 'Create invoice'],
        ]);

        $response = AIResponse::success("Summary:\n- Create invoice\n- Send email");

        $presented = (new ChatResponsePresentationService(new ResponsePointExtractor(), $suggestions))->apply(
            $response,
            'create invoice from this email',
            ['response_points_format' => 'array', 'response_suggestions' => true]
        );

        $this->assertSame('Summary:', $presented->getContent());
        $this->assertSame('array', $presented->getMetadata()['response_points_format']);
        $this->assertSame('Create invoice', $presented->getMetadata()['response_points'][0]['text']);
        $this->assertSame('create_invoice', $presented->getMetadata()['suggestions'][0]['id']);
    }
}
