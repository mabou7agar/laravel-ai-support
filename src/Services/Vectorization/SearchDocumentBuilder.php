<?php

namespace LaravelAIEngine\Services\Vectorization;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelAIEngine\Contracts\AccessScopeProviderInterface;
use LaravelAIEngine\Contracts\GraphObjectInterface;
use LaravelAIEngine\Contracts\GraphRelationProviderInterface;
use LaravelAIEngine\DTOs\SearchDocument;
use LaravelAIEngine\Services\Graph\GraphOntologyService;
use LaravelAIEngine\Traits\Vectorizable;
use ReflectionMethod;

class SearchDocumentBuilder
{
    public function __construct(
        protected ?GraphOntologyService $ontology = null
    ) {
        if ($this->ontology === null && app()->bound(GraphOntologyService::class)) {
            $this->ontology = app(GraphOntologyService::class);
        }
    }

    public function build(object $model): SearchDocument
    {
        if (method_exists($model, 'toSearchDocument')) {
            $document = $model->toSearchDocument();

            if ($document instanceof SearchDocument) {
                return $this->finalize($document, $model);
            }

            if (is_array($document)) {
                return $this->finalize(SearchDocument::fromArray($document, $model), $model);
            }
        }

        return $this->buildFromLegacyModel($model);
    }

    public function buildFromLegacyModel(object $model): SearchDocument
    {
        $modelClass = get_class($model);
        $modelId = $model->id ?? null;
        $content = $this->extractLegacyContent($model);
        $metadata = $this->extractLegacyMetadata($model);
        $title = $this->resolveTitle($model);
        $detail = $this->resolveRagDetail($model, $content);
        $summary = $this->resolveRagSummary($model, $detail);
        $preview = $this->resolveListPreview($model, $summary);
        $object = $this->resolveGraphObject($model, $title, $summary);
        $accessScope = $this->resolveAccessScope($model, $metadata);
        $relations = $this->resolveGraphRelations($model);
        $sourceNode = $this->resolveSourceNode($model, $metadata);
        $appSlug = $this->resolveAppSlug($model, $metadata, $sourceNode);
        [$scopeType, $scopeId, $scopeLabel] = $this->resolveScope($model, $metadata);

        $chunks = [[
            'content' => $content,
            'index' => 0,
        ]];

        return new SearchDocument(
            modelClass: $modelClass,
            modelId: $modelId,
            content: $content,
            title: $title,
            ragContent: $this->resolveRagContent($model, $detail, $content),
            ragSummary: $summary,
            ragDetail: $detail,
            listPreview: $preview,
            metadata: $metadata,
            searchableAttributes: [],
            keywords: $this->extractKeywords($model, $title),
            object: $object,
            accessScope: $accessScope,
            relations: $relations,
            chunks: $chunks,
            sourceNode: $sourceNode,
            appSlug: $appSlug,
            scopeType: $scopeType,
            scopeId: $scopeId,
            scopeLabel: $scopeLabel,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function buildEntityRef(object|array $subject, array $overrides = []): array
    {
        if (is_object($subject)) {
            $document = $this->build($subject);
            $ref = $document->entityRef();
        } else {
            $ref = array_filter([
                'model_id' => $subject['model_id'] ?? $subject['id'] ?? null,
                'model_class' => $subject['model_class'] ?? null,
                'model_type' => $subject['model_type'] ?? (isset($subject['model_class']) ? class_basename((string) $subject['model_class']) : null),
                'source_node' => $subject['source_node'] ?? null,
                'app_slug' => $subject['app_slug'] ?? null,
                'scope_type' => $subject['scope_type'] ?? null,
                'scope_id' => $subject['scope_id'] ?? null,
                'scope_label' => $subject['scope_label'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return array_merge($ref, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function buildGraphObject(object|array $subject, array $overrides = []): array
    {
        if (is_object($subject)) {
            $document = $this->build($subject);

            return array_merge($document->object, $overrides);
        }

        $base = is_array($subject['object'] ?? null)
            ? $subject['object']
            : Arr::only($subject, [
                'id',
                'title',
                'name',
                'subject',
                'status',
                'summary',
                'preview',
                'created_at',
                'updated_at',
                'source_node',
                'app_slug',
            ]);

        return array_merge($base, $overrides);
    }

    protected function finalize(SearchDocument $document, object $model): SearchDocument
    {
        $document->modelClass = $document->modelClass !== '' ? $document->modelClass : get_class($model);
        $document->modelId = $document->modelId ?? ($model->id ?? null);
        $document->content = trim($document->content);
        $document->title = $document->title ?: $this->resolveTitle($model);
        $document->ragDetail = $document->ragDetail ?: $this->resolveRagDetail($model, $document->content);
        $document->ragContent = $document->ragContent ?: $this->resolveRagContent($model, $document->ragDetail, $document->content);
        $document->ragSummary = $document->ragSummary ?: $this->resolveRagSummary($model, $document->ragDetail);
        $document->listPreview = $document->listPreview ?: $this->resolveListPreview($model, $document->ragSummary ?? '');
        $document->metadata = array_merge($this->extractBaseMetadata($model), $document->metadata);
        $document->object = $document->object !== [] ? $document->object : $this->resolveGraphObject($model, $document->title, $document->ragSummary ?? null);
        $document->accessScope = $document->accessScope !== [] ? $document->accessScope : $this->resolveAccessScope($model, $document->metadata);
        $document->relations = $document->relations !== [] ? $document->relations : $this->resolveGraphRelations($model);
        $document->sourceNode = $document->sourceNode ?: $this->resolveSourceNode($model, $document->metadata);
        $document->appSlug = $document->appSlug ?: $this->resolveAppSlug($model, $document->metadata, $document->sourceNode);
        if ($document->scopeType === null && $document->scopeId === null && $document->scopeLabel === null) {
            [$document->scopeType, $document->scopeId, $document->scopeLabel] = $this->resolveScope($model, $document->metadata);
        }

        if ($document->chunks === []) {
            $document->chunks = [[
                'content' => $document->content,
                'index' => 0,
            ]];
        }

        return $document;
    }

    protected function extractLegacyContent(object $model): string
    {
        if (method_exists($model, 'getVectorContent')) {
            return trim((string) $model->getVectorContent());
        }

        $parts = [];
        $vectorizable = $this->readArrayProperty($model, 'vectorizable');
        $fields = $vectorizable !== []
            ? $vectorizable
            : ['title', 'name', 'content', 'description', 'body', 'text', 'subject'];

        foreach ($fields as $field) {
            if (isset($model->$field) && is_scalar($model->$field)) {
                $parts[] = trim((string) $model->$field);
            }
        }

        if (method_exists($model, 'getMediaVectorContent')) {
            $mediaContent = trim((string) $model->getMediaVectorContent());
            if ($mediaContent !== '') {
                $parts[] = $mediaContent;
            }
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractLegacyMetadata(object $model): array
    {
        $metadata = $this->extractBaseMetadata($model);

        if (method_exists($model, 'getVectorMetadata')) {
            foreach ((array) $model->getVectorMetadata() as $key => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $metadata[$key] = $value->format('Y-m-d\TH:i:sP');
                    $metadata[$key . '_ts'] = $value->getTimestamp();
                } else {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractBaseMetadata(object $model): array
    {
        $createdAt = $this->normalizeDateValue($model->created_at ?? null);
        $updatedAt = $this->normalizeDateValue($model->updated_at ?? null);

        return array_filter([
            'model_class' => get_class($model),
            'model_id' => $model->id ?? null,
            'created_at' => $createdAt['iso'],
            'updated_at' => $updatedAt['iso'],
            'created_at_ts' => $createdAt['ts'],
            'updated_at_ts' => $updatedAt['ts'],
        ], static fn ($value) => $value !== null);
    }

    protected function resolveTitle(object $model): ?string
    {
        foreach (['title', 'name', 'subject', 'label'] as $field) {
            if (isset($model->$field) && is_scalar($model->$field)) {
                $value = trim((string) $model->$field);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function resolveRagContent(object $model, string $detail, string $content): string
    {
        if (method_exists($model, 'toRAGContent')) {
            $ragContent = trim((string) $model->toRAGContent());
            if ($ragContent !== '') {
                return $ragContent;
            }
        }

        return $detail !== '' ? $detail : $content;
    }

    protected function resolveRagDetail(object $model, string $fallback): string
    {
        if ($this->hasCustomMethod($model, 'toRAGDetail')) {
            $detail = trim((string) $model->toRAGDetail());
            if ($detail !== '') {
                return $detail;
            }
        }

        if (method_exists($model, 'toRAGContent')) {
            $detail = trim((string) $model->toRAGContent());
            if ($detail !== '') {
                return $detail;
            }
        }

        return $fallback;
    }

    protected function resolveRagSummary(object $model, string $fallback): string
    {
        if (method_exists($model, 'toAISummarySource')) {
            $summary = trim((string) $model->toAISummarySource());
            if ($summary !== '') {
                return $summary;
            }
        }

        if ($this->hasCustomMethod($model, 'toRAGSummary')) {
            $summary = trim((string) $model->toRAGSummary());
            if ($summary !== '') {
                return $summary;
            }
        }

        $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags($fallback)));

        return Str::limit($clean, (int) config('ai-engine.entity_summaries.max_chars', 420), '...');
    }

    protected function resolveListPreview(object $model, string $fallback): string
    {
        if ($this->hasCustomMethod($model, 'toRAGListPreview')) {
            $preview = trim((string) $model->toRAGListPreview(app()->getLocale()));
            if ($preview !== '') {
                return $preview;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function resolveGraphObject(object $model, ?string $title, ?string $summary, array $metadata = []): array
    {
        if ($model instanceof GraphObjectInterface || $this->hasCustomMethod($model, 'toGraphObject')) {
            $object = $model->toGraphObject();
            if (is_array($object)) {
                return $object;
            }
        }

        $data = method_exists($model, 'toArray') ? (array) $model->toArray() : [];
        $hidden = method_exists($model, 'getHidden') ? $model->getHidden() : [];
        $sensitivePatterns = ['password', 'token', 'secret', 'api_key', 'access_key', 'refresh_token'];
        $object = [];

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $keyLower = strtolower($key);
            if (in_array($key, $hidden, true)) {
                continue;
            }

            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($keyLower, $pattern)) {
                    continue 2;
                }
            }

            if (is_scalar($value) || $value === null) {
                $object[$key] = $value;
            }
        }

        $preview = array_filter([
            'id' => $model->id ?? null,
            'title' => $title,
            'status' => $object['status'] ?? null,
            'summary' => $summary,
            'created_at' => $object['created_at'] ?? null,
            'updated_at' => $object['updated_at'] ?? null,
            'source_node' => $metadata['source_node'] ?? null,
            'app_slug' => $metadata['app_slug'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        return $preview + Arr::only($object, ['name', 'subject', 'type', 'label']);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function resolveAccessScope(object $model, array $metadata): array
    {
        if ($model instanceof AccessScopeProviderInterface || $this->hasCustomMethod($model, 'getAccessScope')) {
            $scope = $model->getAccessScope();
            if (is_array($scope) && $scope !== []) {
                return $scope;
            }
        }

        $scope = [];
        foreach ([
            'canonical_user_id',
            'user_id',
            'tenant_id',
            'workspace_id',
            'project_id',
            'organization_id',
            'team_id',
            'account_id',
            'user_email_normalized',
            'email',
        ] as $field) {
            if (array_key_exists($field, $metadata)) {
                $scope[$field] = $metadata[$field];
            } elseif (isset($model->$field)) {
                $scope[$field] = $model->$field;
            }
        }

        if (!isset($scope['user_email_normalized']) && !empty($scope['email'])) {
            $scope['user_email_normalized'] = Str::lower(trim((string) $scope['email']));
        }

        return array_filter($scope, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function resolveGraphRelations(object $model): array
    {
        if ($model instanceof GraphRelationProviderInterface || $this->hasCustomMethod($model, 'getGraphRelations')) {
            $relations = $model->getGraphRelations();
            if (is_array($relations)) {
                return array_values(array_filter($relations, static fn ($relation): bool => is_array($relation)));
            }
        }

        if (!$model instanceof Model) {
            return [];
        }

        $relationNames = [];
        if (method_exists($model, 'getIndexableRelationships')) {
            $configured = $model->getIndexableRelationships(1);
            if (is_array($configured)) {
                $relationNames = $configured;
            }
        } else {
            $relationNames = $this->readArrayProperty($model, 'vectorRelationships');
        }

        if (!(bool) config('ai-engine.graph.extract_relations_from_vector_relationships', true)) {
            return [];
        }

        $limit = max(1, (int) config('ai-engine.graph.max_related_entities_per_relation', 25));
        $relations = [];
        foreach ($relationNames as $relationName) {
            $relationConfig = is_array($relationName) ? $relationName : ['name' => $relationName];
            $name = trim((string) ($relationConfig['name'] ?? ''));
            if ($name === '' || !method_exists($model, $name)) {
                continue;
            }

            try {
                $relation = $model->{$name}();
            } catch (\Throwable) {
                continue;
            }

            if (!$relation instanceof Relation) {
                continue;
            }

            if (!$model->relationLoaded($name)) {
                try {
                    $model->loadMissing($name);
                } catch (\Throwable) {
                    continue;
                }
            }

            $related = $model->getRelationValue($name);
            $relationType = $this->resolveRelationType($relation, $relationConfig);

            foreach ($this->normalizeRelatedModels($related, $limit) as $relatedModel) {
                $relatedId = $relatedModel->getKey() ?? ($relatedModel->id ?? null);
                if ($relatedId === null || $relatedId === '') {
                    continue;
                }

                $relations[] = array_filter([
                    'type' => $relationType,
                    'name' => $name,
                    'model_class' => get_class($relatedModel),
                    'model_id' => $relatedId,
                    'source_node' => $this->resolveRelatedSourceNode($relatedModel),
                ], static fn ($value) => $value !== null && $value !== '');
            }
        }

        return $relations;
    }

    /**
     * @return array<int, Model>
     */
    protected function normalizeRelatedModels(mixed $related, int $limit): array
    {
        if ($related instanceof Model) {
            return [$related];
        }

        if ($related instanceof EloquentCollection) {
            return $related->filter(fn ($item) => $item instanceof Model)->take($limit)->values()->all();
        }

        if (is_iterable($related)) {
            $models = [];
            foreach ($related as $item) {
                if ($item instanceof Model) {
                    $models[] = $item;
                }

                if (count($models) >= $limit) {
                    break;
                }
            }

            return $models;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $relationConfig
     */
    protected function resolveRelationType(Relation $relation, array $relationConfig): string
    {
        $configured = trim((string) ($relationConfig['type'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $relationName = Str::lower(trim((string) ($relationConfig['name'] ?? '')));
        $sourceClass = method_exists($relation, 'getParent') ? get_class($relation->getParent()) : null;
        $targetClass = method_exists($relation, 'getRelated') ? get_class($relation->getRelated()) : null;
        $ontologyType = $this->ontology?->relationTypeFor($relationName, $sourceClass, $targetClass);
        if (is_string($ontologyType) && trim($ontologyType) !== '') {
            return strtoupper(trim($ontologyType));
        }

        if ($relationName !== '') {
            if (in_array($relationName, ['owner', 'ownedby', 'owned_by'], true)) {
                return 'OWNED_BY';
            }

            if (in_array($relationName, ['creator', 'createdby', 'created_by', 'author'], true)) {
                return 'CREATED_BY';
            }

            if (in_array($relationName, ['assignee', 'assignedto', 'assigned_to'], true)) {
                return 'ASSIGNED_TO';
            }

            if (in_array($relationName, ['manager', 'managedby', 'managed_by', 'lead', 'supervisor'], true)) {
                return 'MANAGED_BY';
            }

            if (in_array($relationName, ['reporter', 'reportedby', 'reported_by'], true)) {
                return 'REPORTED_BY';
            }

            if (in_array($relationName, ['sender', 'sentby', 'sent_by'], true)) {
                return 'SENT_BY';
            }

            if (in_array($relationName, ['recipient', 'recipients', 'to', 'receiver'], true)) {
                return 'SENT_TO';
            }

            if (in_array($relationName, ['customer', 'client'], true)) {
                return 'FOR_CUSTOMER';
            }

            if (in_array($relationName, ['vendor', 'supplier'], true)) {
                return 'FOR_VENDOR';
            }

            if (in_array($relationName, ['workspace'], true)) {
                return 'IN_WORKSPACE';
            }

            if (in_array($relationName, ['project'], true)) {
                return 'IN_PROJECT';
            }

            if (in_array($relationName, ['organization', 'organisation'], true)) {
                return 'IN_ORGANIZATION';
            }

            if (in_array($relationName, ['team'], true)) {
                return 'IN_TEAM';
            }

            if (in_array($relationName, ['account'], true)) {
                return 'IN_ACCOUNT';
            }

            if (in_array($relationName, ['folder'], true)) {
                return 'IN_FOLDER';
            }

            if (in_array($relationName, ['channel'], true)) {
                return 'IN_CHANNEL';
            }

            if (in_array($relationName, ['thread', 'conversation'], true)) {
                return 'IN_THREAD';
            }

            if (in_array($relationName, ['milestone'], true)) {
                return 'IN_MILESTONE';
            }

            if (in_array($relationName, ['sprint'], true)) {
                return 'IN_SPRINT';
            }

            if (in_array($relationName, ['dependency', 'dependencies'], true)) {
                return 'DEPENDS_ON';
            }

            if (in_array($relationName, ['blocker', 'blockers'], true)) {
                return 'BLOCKED_BY';
            }

            if (in_array($relationName, ['reply', 'replies', 'replyto', 'reply_to'], true)) {
                return 'REPLIED_TO';
            }

            if (in_array($relationName, ['mention', 'mentions'], true)) {
                return 'MENTIONS';
            }

            if (in_array($relationName, ['member', 'members'], true)) {
                return 'HAS_MEMBER';
            }

            if (in_array($relationName, ['participant', 'participants', 'collaborator', 'collaborators'], true)) {
                return 'HAS_PARTICIPANT';
            }

            if (in_array($relationName, ['watcher', 'watchers', 'subscriber', 'subscribers'], true)) {
                return 'WATCHED_BY';
            }

            if (in_array($relationName, ['attachment', 'attachments', 'file', 'files'], true)) {
                return 'HAS_ATTACHMENT';
            }

            if (in_array($relationName, ['invoice', 'invoices'], true)) {
                return 'HAS_INVOICE';
            }

            if (in_array($relationName, ['order', 'orders'], true)) {
                return 'HAS_ORDER';
            }

            if (in_array($relationName, ['ticket', 'tickets'], true)) {
                return 'HAS_TICKET';
            }

            if (in_array($relationName, ['issue', 'issues', 'bug', 'bugs'], true)) {
                return 'HAS_ISSUE';
            }

            if (in_array($relationName, ['note', 'notes'], true)) {
                return 'HAS_NOTE';
            }

            if (in_array($relationName, ['document', 'documents', 'doc', 'docs'], true)) {
                return 'HAS_DOCUMENT';
            }

            if (in_array($relationName, ['contact', 'contacts'], true)) {
                return 'HAS_CONTACT';
            }

            if (in_array($relationName, ['company', 'companies'], true)) {
                return 'HAS_COMPANY';
            }

            foreach ([
                'mail' => 'HAS_MAIL',
                'email' => 'HAS_MAIL',
                'task' => 'HAS_TASK',
                'project' => 'HAS_PROJECT',
                'workspace' => 'HAS_WORKSPACE',
                'user' => 'HAS_USER',
                'comment' => 'HAS_COMMENT',
                'message' => 'HAS_MESSAGE',
            ] as $prefix => $type) {
                if ($relationName === $prefix . 's' || $relationName === $prefix) {
                    return $type;
                }
            }
        }

        return match (class_basename($relation)) {
            'BelongsTo' => 'BELONGS_TO',
            'HasOne', 'HasMany', 'MorphOne', 'MorphMany' => 'HAS_RELATED',
            'BelongsToMany', 'MorphToMany', 'MorphedByMany' => 'RELATED_TO',
            default => 'RELATED_TO',
        };
    }

    protected function resolveRelatedSourceNode(Model $relatedModel): ?string
    {
        if (method_exists($relatedModel, 'getSourceNode')) {
            $value = trim((string) $relatedModel->getSourceNode());
            if ($value !== '') {
                return $value;
            }
        }

        if (method_exists($relatedModel, 'getVectorMetadata')) {
            try {
                $metadata = (array) $relatedModel->getVectorMetadata();
                $sourceNode = trim((string) ($metadata['source_node'] ?? ''));
                if ($sourceNode !== '') {
                    return $sourceNode;
                }
            } catch (\Throwable) {
                // Ignore relation metadata lookup failures and fall back to local node.
            }
        }

        $configured = config('ai-engine.nodes.local.slug');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    /**
     * @return array<int, mixed>
     */
    protected function readArrayProperty(object $model, string $property): array
    {
        if (!property_exists($model, $property)) {
            return [];
        }

        try {
            $reflection = new \ReflectionProperty($model, $property);
            $reflection->setAccessible(true);
            $value = $reflection->getValue($model);
        } catch (\Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function resolveSourceNode(object $model, array $metadata): ?string
    {
        $sourceNode = $metadata['source_node'] ?? null;
        if (is_string($sourceNode) && trim($sourceNode) !== '') {
            return trim($sourceNode);
        }

        if (method_exists($model, 'getSourceNode')) {
            $value = $model->getSourceNode();
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $configured = config('ai-engine.nodes.local.slug');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function resolveAppSlug(object $model, array $metadata, ?string $sourceNode): string
    {
        $appSlug = $metadata['app_slug'] ?? null;
        if (is_string($appSlug) && trim($appSlug) !== '') {
            return trim($appSlug);
        }

        if (method_exists($model, 'getAppSlug')) {
            $value = $model->getAppSlug();
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($sourceNode) {
            return $sourceNode;
        }

        return Str::slug((string) config('app.name', 'app'));
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{0:?string,1:string|int|null,2:?string}
     */
    protected function resolveScope(object $model, array $metadata): array
    {
        $candidates = [
            ['workspace_id', 'workspace_name', 'workspace'],
            ['project_id', 'project_name', 'project'],
            ['organization_id', 'organization_name', 'organization'],
            ['team_id', 'team_name', 'team'],
            ['account_id', 'account_name', 'account'],
        ];

        foreach ($candidates as [$idKey, $labelKey, $scopeType]) {
            $scopeId = $metadata[$idKey] ?? ($model->$idKey ?? null);
            if ($scopeId === null || $scopeId === '') {
                continue;
            }

            $scopeLabel = $metadata[$labelKey] ?? null;
            if ($scopeLabel === null && isset($model->$scopeType) && is_object($model->$scopeType)) {
                $scopeLabel = $model->{$scopeType}->name ?? $model->{$scopeType}->title ?? null;
            }

            return [$scopeType, $scopeId, is_scalar($scopeLabel) ? (string) $scopeLabel : null];
        }

        return [null, null, null];
    }

    /**
     * @return array<int, string>
     */
    protected function extractKeywords(object $model, ?string $title): array
    {
        $keywords = [];

        if ($title) {
            $keywords[] = $title;
        }

        foreach (['type', 'status', 'category', 'category_id'] as $field) {
            if (isset($model->$field) && is_scalar($model->$field)) {
                $keywords[] = (string) $model->$field;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($keyword): string => trim((string) $keyword),
            $keywords
        ))));
    }

    protected function hasCustomMethod(object $model, string $method): bool
    {
        if (!method_exists($model, $method)) {
            return false;
        }

        try {
            $reflection = new ReflectionMethod($model, $method);
            $fileName = $reflection->getFileName();
            $traitFile = (new ReflectionMethod(Vectorizable::class, $method))->getFileName();

            return $fileName !== false && $traitFile !== false && $fileName !== $traitFile;
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * @return array{iso:?string,ts:?int}
     */
    protected function normalizeDateValue(mixed $value): array
    {
        if ($value instanceof \DateTimeInterface) {
            return [
                'iso' => $value->format('Y-m-d\TH:i:sP'),
                'ts' => $value->getTimestamp(),
            ];
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);

            return [
                'iso' => $value,
                'ts' => $timestamp !== false ? $timestamp : null,
            ];
        }

        return ['iso' => null, 'ts' => null];
    }
}
