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

    public function test_array_format_retains_original_formatted_content_in_metadata(): void
    {
        $suggestions = Mockery::mock(AgentResponseSuggestionService::class);
        $suggestions->shouldReceive('suggest')->once()->andReturn([]);

        $original = "Summary:\n- Create invoice\n- Send email";
        $response = AIResponse::success($original);

        $presented = (new ChatResponsePresentationService(new ResponsePointExtractor(), $suggestions))->apply(
            $response,
            'create invoice from this email',
            ['response_points_format' => 'array']
        );

        $this->assertSame('Summary:', $presented->getContent());
        $this->assertSame($original, $presented->getMetadata()['response_content_original']);
    }

    public function test_existing_required_choice_suggestions_are_preserved_before_generated_suggestions(): void
    {
        $suggestions = Mockery::mock(AgentResponseSuggestionService::class);
        $suggestions->shouldReceive('suggest')->once()->andReturn([
            ['type' => 'skill', 'id' => 'create_invoice', 'label' => 'Create Invoice'],
        ]);

        $response = AIResponse::success('Please confirm before I create this customer.')
            ->withMetadata([
                'suggestions' => [
                    [
                        'type' => 'required_choice',
                        'id' => 'confirm_create_customer',
                        'label' => 'Confirm',
                        'message' => 'confirm',
                        'required' => true,
                    ],
                ],
            ]);

        $presented = (new ChatResponsePresentationService(new ResponsePointExtractor(), $suggestions))->apply(
            $response,
            'Use ahmed@gmail.com',
            ['response_suggestions' => true]
        );

        $this->assertSame('confirm_create_customer', $presented->getMetadata()['suggestions'][0]['id']);
        $this->assertSame('confirm', $presented->getMetadata()['suggestions'][0]['message']);
        $this->assertTrue($presented->getMetadata()['suggestions'][0]['required']);
        $this->assertSame('create_invoice', $presented->getMetadata()['suggestions'][1]['id']);
    }
}
