<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Config;
use LaravelAIEngine\DTOs\StructuredCollectionDefinition;
use LaravelAIEngine\Services\Agent\StructuredCollectionFieldPresenter;
use LaravelAIEngine\Services\Agent\StructuredCollectionPreviewRenderer;
use LaravelAIEngine\Tests\UnitTestCase;

class StructuredCollectionPreviewRendererTest extends UnitTestCase
{
    public function test_it_returns_safe_html_preview_with_external_assets(): void
    {
        Config::set('ai-agent.structured_collection.preview.assets', [
            'css' => ['/vendor/ai-engine/structured-collection.css'],
            'js' => ['/vendor/ai-engine/structured-collection.js'],
        ]);

        $definition = StructuredCollectionDefinition::make('training_request')
            ->addText('name', required: true)
            ->addSelect('level', [
                ['value' => 'beginner', 'labels' => ['en' => 'Beginner', 'ar' => 'مبتدئ']],
                ['value' => 'advanced', 'labels' => ['en' => 'Advanced', 'ar' => 'متقدم']],
            ], required: true)
            ->withPreview('html');

        $fields = (new StructuredCollectionFieldPresenter())->present($definition, 'ar');
        $preview = (new StructuredCollectionPreviewRenderer())->render($definition, [
            'data' => ['name' => '<script>alert(1)</script>', 'level' => 'beginner'],
            'missing_fields' => [],
            'language' => 'ar',
        ], $fields, 'awaiting_confirmation');

        $this->assertSame('html', $preview['type']);
        $this->assertSame(['/vendor/ai-engine/structured-collection.css'], $preview['assets']['css']);
        $this->assertSame(['/vendor/ai-engine/structured-collection.js'], $preview['assets']['js']);
        $this->assertStringContainsString('data-ai-collection="training_request"', $preview['html']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $preview['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $preview['html']);
        $this->assertStringContainsString('مبتدئ', $preview['html']);
        $this->assertStringNotContainsString('<script', $preview['html']);
    }

    public function test_it_can_return_component_contract_without_html(): void
    {
        $definition = StructuredCollectionDefinition::make('training_request')
            ->addEmail('email', required: true)
            ->withPreview('component');

        $fields = (new StructuredCollectionFieldPresenter())->present($definition, 'en');
        $preview = (new StructuredCollectionPreviewRenderer())->render($definition, [
            'data' => [],
            'missing_fields' => ['email'],
        ], $fields, 'collecting');

        $this->assertSame('component', $preview['type']);
        $this->assertSame('ai-structured-collection-form', $preview['component']['name']);
        $this->assertArrayNotHasKey('html', $preview);
        $this->assertSame(['email'], $preview['component']['props']['missing_fields']);
    }
}
