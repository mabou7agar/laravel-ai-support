<?php

namespace LaravelAIEngine\Tests\Unit\Services\Vectorization;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\DTOs\SearchDocument;
use LaravelAIEngine\Models\Transcription;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;

class SearchDocumentBuilderTest extends UnitTestCase
{
    public function test_custom_search_document_takes_precedence_over_legacy_contracts(): void
    {
        $model = new class extends Model {
            protected $table = 'custom_records';
            public $id = 15;

            public function getVectorContent(): string
            {
                return 'legacy content should not be used';
            }

            public function toSearchDocument(): SearchDocument|array
            {
                return [
                    'content' => 'canonical content',
                    'chunks' => [
                        ['content' => 'chunk one', 'index' => 0],
                        ['content' => 'chunk two', 'index' => 1],
                    ],
                    'object' => ['id' => 15, 'title' => 'Canonical Record'],
                    'access_scope' => ['canonical_user_id' => 'user-15'],
                    'source_node' => 'mail',
                    'app_slug' => 'mail',
                ];
            }
        };

        $document = $this->app->make(SearchDocumentBuilder::class)->build($model);

        $this->assertSame('canonical content', $document->content);
        $this->assertCount(2, $document->normalizedChunks());
        $this->assertSame('Canonical Record', $document->object['title']);
        $this->assertSame('mail', $document->entityRef()['app_slug']);
        $this->assertSame('user-15', $document->entityRef()['canonical_user_id']);
    }

    public function test_legacy_vectorizable_model_builds_entity_ref_and_safe_object(): void
    {
        $model = new class extends Model {
            use Vectorizable;

            protected $table = 'documents';
            protected $fillable = ['title', 'content', 'password', 'workspace_id'];
            protected $hidden = ['password'];

            public function __construct(array $attributes = [])
            {
                parent::__construct($attributes);
                $this->id = 9;
            }
        };

        $model->fill([
            'title' => 'Roadmap',
            'content' => 'Shared graph rollout plan',
            'password' => 'secret',
            'workspace_id' => 44,
        ]);

        $document = $this->app->make(SearchDocumentBuilder::class)->build($model);

        $this->assertSame('Roadmap', $document->title);
        $this->assertSame(9, $document->entityRef()['model_id']);
        $this->assertSame('workspace', $document->entityRef()['scope_type']);
        $this->assertSame(44, $document->entityRef()['scope_id']);
        $this->assertArrayNotHasKey('password', $document->object);
    }

    public function test_transcription_uses_vector_content_contract_instead_of_dead_alias(): void
    {
        $transcription = new Transcription([
            'content' => 'Transcript body',
            'language' => 'en',
            'status' => Transcription::STATUS_COMPLETED,
        ]);
        $transcription->id = 101;

        $document = $this->app->make(SearchDocumentBuilder::class)->build($transcription);

        $this->assertSame('Transcript body', $document->content);
        $this->assertStringContainsString('transcription', strtolower((string) $document->ragSummary));
        $this->assertSame(101, $document->entityRef()['model_id']);
    }

    public function test_relation_name_heuristics_emit_richer_graph_edge_types(): void
    {
        $model = new SearchDocumentBuilderOwnerModel();
        $model->id = 7;
        $model->user_id = 1;
        $model->setRelation('owner', tap(new SearchDocumentBuilderUserModel(), function ($user): void {
            $user->id = 1;
            $user->name = 'Graph Owner';
        }));

        $document = $this->app->make(SearchDocumentBuilder::class)->build($model);

        $this->assertSame('OWNED_BY', $document->relations[0]['type'] ?? null);
    }

    public function test_target_model_ontology_can_resolve_relation_type_when_name_is_generic(): void
    {
        $model = new SearchDocumentBuilderPartnerModel();
        $model->id = 12;
        $model->company_id = 77;
        $model->setRelation('linkedPartner', tap(new SearchDocumentBuilderCompanyModel(), function ($company): void {
            $company->id = 77;
            $company->name = 'Osarh Labs';
        }));

        $document = $this->app->make(SearchDocumentBuilder::class)->build($model);

        $this->assertSame('HAS_COMPANY', $document->relations[0]['type'] ?? null);
    }
}

class SearchDocumentBuilderOwnerModel extends Model
{
    use Vectorizable;

    protected $table = 'owner_models';
    protected $fillable = ['user_id', 'title'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->vectorRelationships = ['owner'];
    }

    public function owner()
    {
        return $this->belongsTo(SearchDocumentBuilderUserModel::class, 'user_id');
    }

    public function getVectorContent(): string
    {
        return 'Owner aware content';
    }
}

class SearchDocumentBuilderUserModel extends Model
{
    protected $table = 'owner_users';
    protected $fillable = ['name'];
}

class SearchDocumentBuilderPartnerModel extends Model
{
    use Vectorizable;

    protected $table = 'partner_models';
    protected $fillable = ['company_id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->vectorRelationships = ['linkedPartner'];
    }

    public function linkedPartner()
    {
        return $this->belongsTo(SearchDocumentBuilderCompanyModel::class, 'company_id');
    }

    public function getVectorContent(): string
    {
        return 'Partner aware content';
    }
}

class SearchDocumentBuilderCompanyModel extends Model
{
    protected $table = 'companies';
    protected $fillable = ['name'];
}
