<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\OpenRouter\OpenRouterCatalogNormalizer;
use LaravelAIEngine\Tests\UnitTestCase;

final class OpenRouterCatalogNormalizerTest extends UnitTestCase
{
    public function test_pricing_is_canonical_per_thousand_and_preserves_provider_values(): void
    {
        $pricing = (new OpenRouterCatalogNormalizer())->pricing([
            'prompt' => '0.000008',
            'completion' => '0.000008',
            'image_output' => '0.00003',
            'web_search' => '0.01',
            'overrides' => [['min_prompt_tokens' => 272000, 'prompt' => '0.000016']],
        ]);

        $this->assertSame(0.008, $pricing['input']);
        $this->assertSame(0.03, $pricing['output']);
        $this->assertSame(0.008, $pricing['text_output']);
        $this->assertSame(0.03, $pricing['image_output']);
        $this->assertSame(0.01, $pricing['provider']['web_search']);
        $this->assertSame(272000.0, $pricing['provider']['overrides'][0]['min_prompt_tokens']);
        $this->assertSame(0.000016, $pricing['provider']['overrides'][0]['prompt']);
    }

    public function test_endpoint_details_are_reshaped_for_bulk_catalog_sync(): void
    {
        $model = (new OpenRouterCatalogNormalizer())->fromEndpointDetails([
            'id' => 'openai/gpt-image-2',
            'architecture' => ['modality' => 'text+image->image'],
            'endpoints' => [[
                'context_length' => 400000,
                'max_completion_tokens' => null,
                'supported_parameters' => ['quality', 'aspect_ratio'],
                'pricing' => ['image_output' => '0.00003'],
            ]],
        ]);

        $this->assertSame('openai/gpt-image-2', $model['id']);
        $this->assertSame(400000, $model['context_length']);
        $this->assertSame(['quality', 'aspect_ratio'], $model['supported_parameters']);
        $this->assertSame('0.00003', $model['pricing']['image_output']);
        $this->assertCount(1, $model['endpoints']);
    }
}
