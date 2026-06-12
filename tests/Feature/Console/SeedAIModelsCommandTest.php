<?php

namespace LaravelAIEngine\Tests\Feature\Console;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIModel;

class SeedAIModelsCommandTest extends TestCase
{
    public function test_seed_populates_ai_models_from_package_catalog(): void
    {
        $this->artisan('ai:models:seed')->assertExitCode(0);

        $this->assertGreaterThan(100, AIModel::count());

        $gpt4o = AIModel::where('model_id', 'gpt-4o')->first();
        $this->assertNotNull($gpt4o);
        $this->assertSame('openai', $gpt4o->provider);
        $this->assertEquals(2.0, $gpt4o->metadata['credit_index']);
        $this->assertSame('text', $gpt4o->metadata['content_type']);
    }

    public function test_seed_skips_existing_rows_unless_fresh(): void
    {
        AIModel::create([
            'provider' => 'openai',
            'model_id' => 'gpt-4o',
            'name' => 'Customized GPT-4o',
            'is_active' => true,
            'metadata' => ['credit_index' => 9.9],
        ]);

        $this->artisan('ai:models:seed')->assertExitCode(0);
        $this->assertSame('Customized GPT-4o', AIModel::where('model_id', 'gpt-4o')->value('name'));

        $this->artisan('ai:models:seed', ['--fresh' => true])->assertExitCode(0);
        $this->assertSame('GPT-4o', AIModel::where('model_id', 'gpt-4o')->value('name'));
    }

    public function test_database_row_overrides_packaged_catalog_metadata(): void
    {
        // Manifest says 2.0 — a synced/customized database row must win
        AIModel::create([
            'provider' => 'openai',
            'model_id' => 'gpt-4o',
            'name' => 'GPT-4o (custom)',
            'max_tokens' => 256000,
            'supports_vision' => true,
            'supports_streaming' => true,
            'is_active' => true,
            'metadata' => ['credit_index' => 9.9],
        ]);

        $entity = EntityEnum::from('gpt-4o');
        $this->assertEquals(9.9, $entity->creditIndex());
        $this->assertSame(256000, $entity->maxTokens());
        $this->assertSame('GPT-4o (custom)', $entity->label());
    }

    public function test_catalog_serves_metadata_without_database_rows(): void
    {
        $this->assertSame(0, AIModel::count());

        $entity = EntityEnum::from('gpt-4o');
        $this->assertEquals(2.0, $entity->creditIndex());
        $this->assertSame(128000, $entity->maxTokens());
        $this->assertTrue($entity->supportsVision());
        $this->assertSame('GPT-4o', $entity->label());
        $this->assertSame('openai', $entity->engine()->value);
    }
}
