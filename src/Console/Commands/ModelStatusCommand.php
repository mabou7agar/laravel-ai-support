<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Contracts\AccessScopeProviderInterface;
use LaravelAIEngine\Contracts\GraphObjectInterface;
use LaravelAIEngine\Contracts\GraphRelationProviderInterface;
use LaravelAIEngine\Contracts\SearchDocumentInterface;
use LaravelAIEngine\Services\Graph\GraphBackendResolver;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Traits\Vectorizable;
use ReflectionClass;
use Throwable;

class ModelStatusCommand extends Command
{
    protected $signature = 'ai:model-status
                            {model : Fully-qualified model class}
                            {--id= : Optional record id to inspect}
                            {--json : Output a JSON payload instead of tables}';

    protected $description = 'Inspect whether a model is ready for indexing, graph publishing, and chat retrieval';

    public function handle(
        SearchDocumentBuilder $documentBuilder,
        Neo4jGraphSyncService $graphSync,
        GraphBackendResolver $backendResolver
    ): int {
        $modelClass = trim((string) $this->argument('model'));

        if (!class_exists($modelClass)) {
            $this->error(sprintf('Model class [%s] does not exist.', $modelClass));

            return self::FAILURE;
        }

        if (!is_subclass_of($modelClass, Model::class)) {
            $this->error(sprintf('Class [%s] is not an Eloquent model.', $modelClass));

            return self::FAILURE;
        }

        $recordId = $this->option('id');
        $model = $this->resolveModel($modelClass, $recordId);

        if ($model === null) {
            $this->error(sprintf('Could not resolve model [%s]%s.', $modelClass, $recordId !== null ? " with id [{$recordId}]" : ''));

            return self::FAILURE;
        }

        $status = $this->statusFor($model, $documentBuilder, $graphSync, $backendResolver, $recordId);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Model status for %s', $modelClass));
        $this->table(['Check', 'Value'], [
            ['resolved_record', $status['resolved_record']],
            ['uses_vectorizable_trait', $status['uses_vectorizable_trait']],
            ['preferred_contract', $status['preferred_contract']],
            ['indexing_ready', $status['indexing_ready']],
            ['graph_payload_ready', $status['graph_payload_ready']],
            ['effective_backend', $status['effective_backend']],
            ['backend_fallback_reason', $status['backend_fallback_reason']],
            ['vector_collection', $status['vector_collection']],
            ['should_be_indexed', $status['should_be_indexed']],
            ['document_content_length', (string) $status['document_content_length']],
            ['chunks', (string) $status['chunk_count']],
            ['relations', (string) $status['relation_count']],
            ['object_fields', (string) $status['object_field_count']],
            ['access_scope_fields', (string) $status['access_scope_field_count']],
        ]);

        $this->newLine();
        $this->info('Method sources');
        $this->table(['Method', 'Status'], array_map(
            static fn (string $method, string $state): array => [$method, $state],
            array_keys($status['method_status']),
            array_values($status['method_status'])
        ));

        if (!empty($status['warnings'])) {
            $this->newLine();
            $this->warn('Warnings');
            foreach ($status['warnings'] as $warning) {
                $this->line(sprintf('- %s', $warning));
            }
        }

        return self::SUCCESS;
    }

    protected function resolveModel(string $modelClass, mixed $recordId): ?Model
    {
        if ($recordId !== null && $recordId !== '') {
            return $modelClass::query()->find($recordId);
        }

        return new $modelClass();
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusFor(
        Model $model,
        SearchDocumentBuilder $documentBuilder,
        Neo4jGraphSyncService $graphSync,
        GraphBackendResolver $backendResolver,
        mixed $recordId
    ): array {
        $reflection = new ReflectionClass($model);
        $warnings = [];
        $document = null;
        $documentError = null;
        $graphPayload = null;
        $graphPayloadError = null;

        try {
            $document = $documentBuilder->build($model);
        } catch (Throwable $e) {
            $documentError = $e->getMessage();
            $warnings[] = 'SearchDocumentBuilder failed: '.$e->getMessage();
        }

        try {
            $graphPayload = $graphSync->buildEntityPayload($model);
        } catch (Throwable $e) {
            $graphPayloadError = $e->getMessage();
            $warnings[] = 'Graph payload build failed: '.$e->getMessage();
        }

        $shouldBeIndexed = method_exists($model, 'shouldBeIndexed')
            ? $this->safeShouldBeIndexed($model, $warnings)
            : null;

        if ($document !== null && trim($document->content) === '') {
            $warnings[] = 'Resolved search document content is empty.';
        }

        return [
            'model_class' => $model::class,
            'resolved_record' => $recordId !== null && $recordId !== '' ? 'loaded' : 'new_instance',
            'uses_vectorizable_trait' => $this->usesVectorizableTrait($reflection) ? 'yes' : 'no',
            'preferred_contract' => $this->preferredContract($model),
            'indexing_ready' => $document !== null && trim($document->content) !== '' ? 'yes' : 'no',
            'graph_payload_ready' => $graphPayload !== null ? 'yes' : 'no',
            'effective_backend' => $backendResolver->effectiveReadBackend(),
            'backend_fallback_reason' => $backendResolver->fallbackReason() ?? '(none)',
            'vector_collection' => method_exists($model, 'getVectorCollectionName')
                ? (string) $model->getVectorCollectionName()
                : '(not available)',
            'should_be_indexed' => $shouldBeIndexed === null ? '(not available)' : ($shouldBeIndexed ? 'yes' : 'no'),
            'document_content_length' => $document !== null ? strlen($document->content) : 0,
            'chunk_count' => $document !== null ? count($document->normalizedChunks()) : 0,
            'relation_count' => $document !== null ? count($document->relations) : 0,
            'object_field_count' => $document !== null ? count($document->object) : 0,
            'access_scope_field_count' => $document !== null ? count($document->accessScope) : 0,
            'document_error' => $documentError,
            'graph_payload_error' => $graphPayloadError,
            'method_status' => [
                'toSearchDocument' => $this->methodStatus($reflection, 'toSearchDocument'),
                'toGraphObject' => $this->methodStatus($reflection, 'toGraphObject'),
                'getAccessScope' => $this->methodStatus($reflection, 'getAccessScope'),
                'getGraphRelations' => $this->methodStatus($reflection, 'getGraphRelations'),
                'toRAGSummary' => $this->methodStatus($reflection, 'toRAGSummary'),
                'toRAGDetail' => $this->methodStatus($reflection, 'toRAGDetail'),
                'toRAGListPreview' => $this->methodStatus($reflection, 'toRAGListPreview'),
                'shouldBeIndexed' => $this->methodStatus($reflection, 'shouldBeIndexed'),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    protected function preferredContract(Model $model): string
    {
        if ($model instanceof SearchDocumentInterface || $this->hasCustomMethod($model, 'toSearchDocument')) {
            return 'canonical_search_document';
        }

        return 'no_supported_contract';
    }

    protected function methodStatus(ReflectionClass $reflection, string $method): string
    {
        if (! $reflection->hasMethod($method)) {
            return 'missing';
        }

        if ($this->isVectorizableTraitMethod($reflection, $method)) {
            return 'trait_default';
        }

        return 'custom';
    }

    protected function hasCustomMethod(object $model, string $method): bool
    {
        if (!method_exists($model, $method)) {
            return false;
        }

        $reflection = new ReflectionClass($model);
        if (! $reflection->hasMethod($method)) {
            return false;
        }

        return ! $this->isVectorizableTraitMethod($reflection, $method);
    }

    protected function usesVectorizableTrait(ReflectionClass $reflection): bool
    {
        return in_array(Vectorizable::class, $reflection->getTraitNames(), true);
    }

    protected function isVectorizableTraitMethod(ReflectionClass $reflection, string $method): bool
    {
        if (! $this->usesVectorizableTrait($reflection) || ! $reflection->hasMethod($method)) {
            return false;
        }

        $methodFile = $reflection->getMethod($method)->getFileName();

        if (! is_string($methodFile)) {
            return false;
        }

        $methodPath = realpath($methodFile);
        if ($methodPath === false) {
            return false;
        }

        foreach ($this->vectorizableTraitFiles() as $traitFile) {
            $traitPath = realpath($traitFile);
            if ($traitPath !== false && $methodPath === $traitPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function vectorizableTraitFiles(): array
    {
        return $this->traitFiles(Vectorizable::class);
    }

    /**
     * @return list<string>
     */
    protected function traitFiles(string $trait): array
    {
        $reflection = new ReflectionClass($trait);
        $files = [];
        $fileName = $reflection->getFileName();

        if (is_string($fileName)) {
            $files[] = $fileName;
        }

        foreach ($reflection->getTraitNames() as $usedTrait) {
            array_push($files, ...$this->traitFiles($usedTrait));
        }

        return array_values(array_unique($files));
    }

    protected function safeShouldBeIndexed(Model $model, array &$warnings): ?bool
    {
        try {
            return (bool) $model->shouldBeIndexed();
        } catch (Throwable $e) {
            $warnings[] = 'shouldBeIndexed failed: '.$e->getMessage();

            return null;
        }
    }
}
