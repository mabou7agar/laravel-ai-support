<?php

namespace LaravelAIEngine\Tests\Unit\Services\Summary;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\Summary\EntitySummaryService;
use LaravelAIEngine\Tests\UnitTestCase;

class EntitySummaryServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('summary_test_models');
        Schema::create('summary_test_models', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('ai_entity_summaries');
        Schema::create('ai_entity_summaries', function ($table) {
            $table->id();
            $table->string('summaryable_type');
            $table->string('summaryable_id', 191);
            $table->string('locale', 16)->default('en');
            $table->text('summary');
            $table->string('source_hash', 64)->nullable();
            $table->string('policy_version', 32)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['summaryable_type', 'summaryable_id', 'locale'], 'ai_entity_summaries_entity_locale_unique');
        });
    }

    public function test_summary_for_display_persists_polymorphic_summary(): void
    {
        config()->set('ai-engine.entity_summaries.enabled', true);
        config()->set('ai-engine.entity_summaries.max_chars', 120);

        $model = SummaryTestModel::create([
            'name' => 'Invoice 3901',
            'details' => str_repeat('Line item details ', 20),
        ]);

        $service = new EntitySummaryService();
        $summary = $service->summaryForDisplay($model, 'en');

        $this->assertNotNull($summary);
        $this->assertLessThanOrEqual(120, strlen($summary));
        $this->assertDatabaseHas('ai_entity_summaries', [
            'summaryable_type' => $model->getMorphClass(),
            'summaryable_id' => (string) $model->getKey(),
            'locale' => 'en',
        ]);
    }

    public function test_summary_for_display_updates_when_source_changes(): void
    {
        config()->set('ai-engine.entity_summaries.enabled', true);

        $model = SummaryTestModel::create([
            'name' => 'Before',
            'details' => 'Old content',
        ]);

        $service = new EntitySummaryService();
        $first = $service->summaryForDisplay($model, 'en');

        $model->update([
            'name' => 'After',
            'details' => 'New content',
        ]);
        $model->refresh();

        $second = $service->summaryForDisplay($model, 'en');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('ai_entity_summaries', 1);
    }
}

class SummaryTestModel extends Model
{
    protected $table = 'summary_test_models';

    protected $fillable = ['name', 'details'];

    public function toRAGSummary(): string
    {
        return "Name: {$this->name} | Details: {$this->details}";
    }
}

