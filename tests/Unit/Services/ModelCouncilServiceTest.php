<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ModelCouncilService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ModelCouncilServiceTest extends TestCase
{
    public function test_runs_prompt_across_all_members(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateText')
            ->twice()
            ->andReturnUsing(function (AIRequest $request): AIResponse {
                return AIResponse::success(
                    'reply from ' . $request->getModel()->value,
                    $request->getEngine(),
                    $request->getModel(),
                );
            });

        $council = new ModelCouncilService($ai);

        $results = $council->run('Compare these', [
            ['engine' => 'openai', 'model' => EntityEnum::GPT_4O],
            ['engine' => 'anthropic', 'model' => EntityEnum::CLAUDE_3_5_SONNET],
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);
        $this->assertStringContainsString('reply from', $results[0]['content']);
    }

    public function test_one_member_failure_does_not_abort_the_rest(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateText')
            ->andReturnUsing(function (AIRequest $request): AIResponse {
                if ($request->getEngine() === EngineEnum::Anthropic) {
                    throw new \RuntimeException('provider down');
                }

                return AIResponse::success('ok', $request->getEngine(), $request->getModel());
            });

        $council = new ModelCouncilService($ai);

        $results = $council->run('Hi', [
            ['engine' => 'openai', 'model' => EntityEnum::GPT_4O],
            ['engine' => 'anthropic', 'model' => EntityEnum::CLAUDE_3_5_SONNET],
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertFalse($results[1]['success']);
        $this->assertSame('provider down', $results[1]['error']);
    }

    public function test_members_without_a_model_are_skipped(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateText')
            ->once()
            ->andReturn(AIResponse::success('ok', EngineEnum::OpenAI, EntityEnum::GPT_4O));

        $council = new ModelCouncilService($ai);

        $results = $council->run('Hi', [
            ['engine' => 'openai', 'model' => EntityEnum::GPT_4O],
            ['engine' => 'openai', 'model' => ''],
        ]);

        $this->assertCount(1, $results);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
