<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionIntakeFlowService;
use LaravelAIEngine\Services\Agent\AgentIntentUnderstandingService;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class IntentSignalServiceTest extends UnitTestCase
{
    public function test_it_detects_affirmative_and_negative_intents_from_locale_lexicon(): void
    {
        app()->setLocale('ar');

        $signals = app(IntentSignalService::class);

        $this->assertTrue($signals->isAffirmative('تمام نفذ'));
        $this->assertTrue($signals->isNegative('لا أوافق'));
        $this->assertFalse($signals->isAffirmative('yesterday was busy'));
    }

    public function test_relation_decisions_are_locale_driven(): void
    {
        app()->setLocale('ar');

        $service = app(ActionIntakeFlowService::class);

        $this->assertSame([
            'use_existing' => true,
            'create_new' => false,
        ], $service->relationDecision('استخدم الموجود'));

        $this->assertSame([
            'use_existing' => false,
            'create_new' => true,
        ], $service->relationDecision('انشئ جديد'));
    }

    public function test_ai_intent_understands_arabic_confirmation_without_regex_terms(): void
    {
        config()->set('ai-agent.intent_understanding.mode', 'ai_first');
        config()->set('ai-agent.intent_understanding.min_confidence', 0.6);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn ($request): bool => str_contains($request->prompt, 'تمام نفذ الآن')))
            ->andReturn(AIResponse::success(json_encode([
                'route' => 'ask_ai',
                'mode' => 'action_flow',
                'intent' => 'confirm',
                'confidence' => 0.94,
                'reason' => 'Arabic approval.',
            ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini'));

        $decision = (new AgentIntentUnderstandingService($ai))->decide(
            'تمام نفذ الآن',
            new UnifiedActionContext('intent-session', 7)
        );

        $this->assertSame('confirm', $decision->intent);
        $this->assertSame(0.94, $decision->confidence);
    }
}
