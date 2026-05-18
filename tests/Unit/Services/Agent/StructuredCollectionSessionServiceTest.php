<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\StructuredCollectionDefinition;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\StructuredCollectionCallbackService;
use LaravelAIEngine\Services\Agent\StructuredCollectionFieldPresenter;
use LaravelAIEngine\Services\Agent\StructuredCollectionPreviewRenderer;
use LaravelAIEngine\Services\Agent\StructuredCollectionSessionService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class StructuredCollectionSessionServiceTest extends UnitTestCase
{
    public function test_collection_uses_ai_to_collect_confirm_close_and_call_callback_in_user_language(): void
    {
        Http::fake(['https://callback.test/leads' => Http::response(['ok' => true])]);

        $definition = StructuredCollectionDefinition::make('lead_capture')
            ->addField('name', 'string', required: true)
            ->addField('email', 'string', required: true, format: 'email')
            ->confirmBeforeComplete()
            ->closeOnComplete()
            ->callbackUrl('https://callback.test/leads');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->times(3)
            ->with(Mockery::on(function (AIRequest $request): bool {
                $prompt = $request->getPrompt();

                return str_contains($prompt, 'reply in the same language')
                    && str_contains($prompt, 'Do not translate JSON field keys')
                    && str_contains($prompt, 'Do not ask for optional fields')
                    && str_contains($prompt, 'For enum fields and option fields, store only canonical values');
            }))
            ->andReturn(
                AIResponse::success(json_encode([
                    'data_patch' => ['name' => 'Ahmed'],
                    'assistant_message' => 'ما البريد الإلكتروني؟',
                    'language' => 'ar',
                ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini'),
                AIResponse::success(json_encode([
                    'data_patch' => ['email' => 'ahmed@example.com'],
                    'assistant_message' => 'البيانات مكتملة: أحمد، ahmed@example.com. هل تؤكد؟',
                    'language' => 'ar',
                    'ready_for_confirmation' => true,
                ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini'),
                AIResponse::success(json_encode([
                    'user_confirmed' => true,
                    'assistant_message' => 'تم استلام البيانات.',
                    'language' => 'ar',
                ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini')
            );

        $service = new StructuredCollectionSessionService(
            $ai,
            new StructuredCollectionCallbackService(),
            new StructuredCollectionFieldPresenter(),
            new StructuredCollectionPreviewRenderer()
        );

        $first = $service->handle('اسمي أحمد', 'lead-session', 'user-uuid', [
            'collection' => $definition->toArray(),
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertNotNull($first);
        $this->assertSame('ما البريد الإلكتروني؟', $first->getContent());
        $this->assertSame('collecting', $first->metadata['collection']['status']);
        $this->assertSame(['email'], $first->metadata['collection']['missing_fields']);
        $this->assertSame('ar', $first->metadata['collection']['language']);

        $second = $service->handle('ahmed@example.com', 'lead-session', 'user-uuid', [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertNotNull($second);
        $this->assertSame('awaiting_confirmation', $second->metadata['collection']['status']);
        $this->assertSame([], $second->metadata['collection']['missing_fields']);
        Http::assertNothingSent();

        $third = $service->handle('نعم أؤكد', 'lead-session', 'user-uuid', [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertNotNull($third);
        $this->assertSame('completed', $third->metadata['collection']['status']);
        $this->assertTrue($third->metadata['collection']['completed']);
        $this->assertFalse($service->isActive('lead-session', 'user-uuid'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://callback.test/leads'
            && $request->data()['session_id'] === 'lead-session'
            && $request->data()['user_id'] === 'user-uuid'
            && $request->data()['status'] === 'completed'
            && $request->data()['data']['name'] === 'Ahmed'
            && $request->data()['data']['email'] === 'ahmed@example.com');
    }

    public function test_collection_is_ignored_when_not_enabled_and_no_active_session_exists(): void
    {
        $service = new StructuredCollectionSessionService(
            Mockery::mock(AIEngineService::class),
            new StructuredCollectionCallbackService(),
            new StructuredCollectionFieldPresenter(),
            new StructuredCollectionPreviewRenderer()
        );

        $this->assertNull($service->handle('hello', 'no-collection', null, []));
        $this->assertFalse(Cache::has('agent_structured_collection:no-collection:guest'));
    }

    public function test_collection_response_includes_presented_fields(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addSelect('level', [
                ['value' => 'beginner', 'labels' => ['en' => 'Beginner', 'ar' => 'مبتدئ']],
                ['value' => 'advanced', 'labels' => ['en' => 'Advanced', 'ar' => 'متقدم']],
            ], required: true);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                'data_patch' => [],
                'assistant_message' => 'ما المستوى المطلوب؟',
                'language' => 'ar',
            ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini'));

        $service = new StructuredCollectionSessionService(
            $ai,
            new StructuredCollectionCallbackService(),
            new StructuredCollectionFieldPresenter(),
            new StructuredCollectionPreviewRenderer()
        );

        $response = $service->handle('أريد تدريب', 'training-session', null, [
            'collection' => $definition->toArray(),
        ]);

        $this->assertNotNull($response);
        $this->assertSame([
            ['value' => 'beginner', 'label' => 'مبتدئ'],
            ['value' => 'advanced', 'label' => 'متقدم'],
        ], $response->metadata['collection']['fields'][0]['options']);
        $this->assertSame('select', $response->metadata['collection']['fields'][0]['ui']);
    }

    public function test_confirmation_turn_does_not_use_ai_message_that_asks_for_optional_fields(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addText('name', required: true)
            ->addEmail('email', required: true)
            ->addSelect('level', [
                ['value' => 'beginner', 'labels' => ['en' => 'Beginner', 'ar' => 'مبتدئ']],
                ['value' => 'advanced', 'labels' => ['en' => 'Advanced', 'ar' => 'متقدم']],
            ], required: true)
            ->addDate('start_date');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->andReturn(
                AIResponse::success(json_encode([
                    'data_patch' => ['name' => 'أحمد', 'level' => 'beginner'],
                    'assistant_message' => 'ما البريد الإلكتروني؟',
                    'language' => 'ar',
                ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini'),
                AIResponse::success(json_encode([
                    'data_patch' => ['email' => 'ahmed@example.com'],
                    'assistant_message' => 'الرجاء تزويدي بتاريخ البدء.',
                    'language' => 'ar',
                    'ready_for_confirmation' => true,
                ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini')
            );

        $service = new StructuredCollectionSessionService(
            $ai,
            new StructuredCollectionCallbackService(),
            new StructuredCollectionFieldPresenter(),
            new StructuredCollectionPreviewRenderer()
        );

        $service->handle('اسمي أحمد ومستواي مبتدئ', 'training-confirmation', null, [
            'collection' => $definition->toArray(),
        ]);

        $response = $service->handle('بريدي ahmed@example.com', 'training-confirmation', null, []);

        $this->assertNotNull($response);
        $this->assertSame('awaiting_confirmation', $response->metadata['collection']['status']);
        $this->assertStringContainsString('تأكيد', $response->getContent());
        $this->assertStringNotContainsString('تاريخ البدء', $response->getContent());
    }

    public function test_collection_response_includes_preview_when_enabled(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addText('name', required: true)
            ->withPreview('component');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                'data_patch' => ['name' => 'Ahmed'],
                'assistant_message' => 'Please confirm.',
                'language' => 'en',
            ], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o-mini'));

        $service = new StructuredCollectionSessionService(
            $ai,
            new StructuredCollectionCallbackService(),
            new StructuredCollectionFieldPresenter(),
            new StructuredCollectionPreviewRenderer()
        );

        $response = $service->handle('My name is Ahmed', 'preview-session', null, [
            'collection' => $definition->toArray(),
        ]);

        $this->assertNotNull($response);
        $this->assertSame('component', $response->metadata['collection']['preview']['type']);
        $this->assertSame('ai-structured-collection-form', $response->metadata['collection']['preview']['component']['name']);
    }
}
