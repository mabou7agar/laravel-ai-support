<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;

class ModelStatusCommandTest extends UnitTestCase
{
    public function test_it_reports_canonical_contract_models_as_ready(): void
    {
        Artisan::call('ai:model-status', [
            'model' => CanonicalVectorModel::class,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"preferred_contract": "canonical_search_document"', $output);
        $this->assertStringContainsString('"indexing_ready": "yes"', $output);
        $this->assertStringContainsString('"graph_payload_ready": "yes"', $output);
    }

    public function test_it_reports_models_without_search_document_contract_as_not_supported(): void
    {
        Artisan::call('ai:model-status', [
            'model' => DefaultVectorModel::class,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('"preferred_contract": "no_supported_contract"', $output);
        $this->assertStringContainsString('"indexing_ready": "yes"', $output);
    }

    public function test_it_supports_json_output(): void
    {
        Artisan::call('ai:model-status', [
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

class DefaultVectorModel extends Model
{
    use Vectorizable;

    protected $table = 'default_vectors';

    public $id = 9;
    public $title = 'Default Apollo';
    public $body = 'Default vector body';

    public function shouldBeIndexed(): bool
    {
        return true;
    }

    public function getQdrantIndexes(): array
    {
        return [];
    }
}
