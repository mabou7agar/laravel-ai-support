<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Contracts\VectorizableInterface;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;

class ModelStatusCommandTest extends UnitTestCase
{
    public function test_it_reports_canonical_contract_models_as_ready(): void
    {
        Artisan::call('ai-engine:model-status', [
            'model' => CanonicalVectorModel::class,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"preferred_contract": "canonical_search_document"', $output);
        $this->assertStringContainsString('"indexing_ready": "yes"', $output);
        $this->assertStringContainsString('"graph_payload_ready": "yes"', $output);
    }

    public function test_it_reports_legacy_vectorizable_models_using_trait_defaults(): void
    {
        Artisan::call('ai-engine:model-status', [
            'model' => LegacyVectorModel::class,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"preferred_contract": "legacy_vectorizable"', $output);
        $this->assertStringContainsString('"indexing_ready": "yes"', $output);
        $this->assertStringContainsString('"toSearchDocument": "trait_default"', $output);
    }

    public function test_it_supports_json_output(): void
    {
        Artisan::call('ai-engine:model-status', [
            'model' => CanonicalVectorModel::class,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"preferred_contract": "canonical_search_document"', $output);
        $this->assertStringContainsString('"indexing_ready": "yes"', $output);
    }
}

class CanonicalVectorModel extends Model
{
    use Vectorizable;

    protected $table = 'canonical_vectors';

    public $id = 7;
    public $title = 'Apollo';

    public function toSearchDocument(): array
    {
        return [
            'title' => 'Apollo',
            'content' => 'Apollo project launch status and timeline',
            'source_node' => 'projects',
            'app_slug' => 'projects',
            'object' => [
                'id' => 7,
                'title' => 'Apollo',
            ],
            'access_scope' => [
                'canonical_user_id' => 'user-7',
            ],
            'relations' => [
                ['type' => 'OWNED_BY', 'target_entity_key' => 'users:App\\Models\\User:7'],
            ],
            'chunks' => [
                ['content' => 'Apollo project launch status and timeline', 'index' => 0],
            ],
        ];
    }

    public function shouldBeIndexed(): bool
    {
        return true;
    }
}

class LegacyVectorModel extends Model implements VectorizableInterface
{
    use Vectorizable;

    protected $table = 'legacy_vectors';

    public $id = 9;
    public $title = 'Legacy Apollo';
    public $body = 'Legacy vector body';

    public function getVectorContent(): string
    {
        return $this->title."\n\n".$this->body;
    }

    public function getVectorMetadata(): array
    {
        return ['status' => 'active'];
    }

    public function toRAGContent(): string
    {
        return $this->getVectorContent();
    }

    public function shouldBeIndexed(): bool
    {
        return true;
    }

    public function getQdrantIndexes(): array
    {
        return [];
    }
}
