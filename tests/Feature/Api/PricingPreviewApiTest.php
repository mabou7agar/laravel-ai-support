<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class PricingPreviewApiTest extends TestCase
{
    public function test_pricing_preview_returns_credit_breakdown_without_deducting(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'fal_ai' => [
                    EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/ai/pricing/preview', [
            'engine' => 'fal_ai',
            'model' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            'prompt' => 'Animate this product',
            'parameters' => [
                'image_url' => 'https://example.test/product.png',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.engine', 'fal_ai')
            ->assertJsonPath('data.model', EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO)
            ->assertJsonPath('data.final_credits', 11.05);

        $user->refresh();
        $this->assertSame(100.0, (float) data_get($user->entity_credits, 'fal_ai.'.EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO.'.balance'));
    }
}
