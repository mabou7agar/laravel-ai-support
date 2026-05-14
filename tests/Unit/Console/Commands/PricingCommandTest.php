<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class PricingCommandTest extends TestCase
{
    public function test_pricing_audit_reports_engine_rates_and_input_media_rates_as_json(): void
    {
        Config::set('ai-engine.credits.engine_rates.gemini', 1.2);
        Config::set('ai-engine.credits.engine_rates.fal_ai', 1.3);

        Artisan::call('ai-engine:pricing-audit', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1.2, $payload['engine_rates']['gemini']['rate']);
        $this->assertSame(1.3, $payload['engine_rates']['fal_ai']['rate']);
        $this->assertSame(0.25, $payload['additional_input_unit_rates']['fal_ai']['default']['image']);
        $this->assertContains('configured_provider_margin', $payload['engine_rates']['fal_ai']['flags']);
    }

    public function test_pricing_audit_warns_about_zero_or_discounted_rates(): void
    {
        Config::set('ai-engine.credits.engine_rates.fal_ai', 0.75);
        Config::set('ai-engine.credits.engine_rates.gemini', 0.0);

        Artisan::call('ai-engine:pricing-audit', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertContains('discounted_provider_rate', $payload['engine_rates']['fal_ai']['flags']);
        $this->assertContains('free_or_disabled_rate', $payload['engine_rates']['gemini']['flags']);
        $this->assertNotEmpty($payload['warnings']);
    }

    public function test_pricing_simulate_explains_fal_reference_image_charge(): void
    {
        Config::set('ai-engine.credits.engine_rates.fal_ai', 1.3);

        Artisan::call('ai-engine:pricing-simulate', [
            'engine' => 'fal_ai',
            'model' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            '--prompt' => 'Animate this product',
            '--parameters' => json_encode([
                'image_url' => 'https://example.test/product.png',
            ]),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('fal_ai', $payload['engine']);
        $this->assertSame(EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO, $payload['model']);
        $this->assertEqualsWithDelta(8.0, $payload['base_engine_credits'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $payload['additional_input_engine_credits'], 0.0001);
        $this->assertSame(1.3, $payload['engine_rate']);
        $this->assertEqualsWithDelta(11.05, $payload['final_credits'], 0.0001);
    }
}
