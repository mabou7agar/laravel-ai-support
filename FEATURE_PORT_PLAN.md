# Feature Port Plan: Bites Vector Indexer → Laravel AI Engine

## 🎯 Overview
Port advanced features from `bites/laravel-vector-indexer` to `m-tech-stack/laravel-ai-engine`

---

## ✅ Current Status

### Already Implemented
- ✅ Basic vector search
- ✅ Vector chat (intelligentChat, vectorChat)
- ✅ RAG integration
- ✅ Model discovery (discover_vectorizable_models helper)
- ✅ Content generation (ask, summarize, generateTags)
- ✅ VectorIndexCommand with auto-discovery

### Missing Features (To Port)
- ❌ Relationship indexing
- ❌ Relationship watchers
- ❌ Auto-reindex on relation changes
- ❌ Media embeddings (images, audio, video)
- ❌ Audio transcription
- ❌ Schema analyzer
- ❌ Chunking service
- ❌ Dynamic observers
- ❌ Configuration UI

---

## 📋 Implementation Plan

### Phase 1: Core Relationship Features

#### 1.1 VectorRelationshipWatcher Model ✅ STARTED
**Source:** `/packagez/bites/laravel-vector-indexer/src/Models/VectorRelationshipWatcher.php`
**Target:** `/packages/laravel-ai-engine/src/Models/VectorRelationshipWatcher.php`

**Fields:**
- `vector_configuration_id` - FK to configuration
- `parent_model` - Parent model class
- `related_model` - Related model class
- `relationship_name` - Relationship method name
- `relationship_type` - hasMany, belongsTo, etc.
- `relationship_path` - Dot notation path (e.g., "author.company")
- `depth` - Relationship depth (1, 2, 3...)
- `watch_fields` - Array of fields to watch
- `on_change_action` - Action on change (reindex_parent, etc.)
- `enabled` - Boolean

**Methods:**
- `isWatchingField(string $field): bool`
- `getInverseRelationshipName(): string`
- `shouldReindexParent(): bool`
- Scopes: `enabled()`, `forParentModel()`, `forRelatedModel()`, `atDepth()`, `shallow()`, `deep()`

**Migration:**
```php
Schema::create('vector_relationship_watchers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('vector_configuration_id')->constrained()->onDelete('cascade');
    $table->string('parent_model');
    $table->string('related_model');
    $table->string('relationship_name');
    $table->string('relationship_type'); // hasMany, belongsTo, etc.
    $table->string('relationship_path')->nullable(); // For nested: author.company
    $table->integer('depth')->default(1);
    $table->json('watch_fields')->nullable(); // Specific fields to watch
    $table->string('on_change_action')->default('reindex_parent');
    $table->boolean('enabled')->default(true);
    $table->timestamps();
    
    $table->index(['parent_model', 'enabled']);
    $table->index(['related_model', 'enabled']);
});
```

#### 1.2 Update VectorConfiguration Model
**Add fields:**
- `relationships` (JSON) - Array of relationship names to index
- `max_relationship_depth` (integer) - How deep to traverse

**Add relationship:**
```php
public function relationshipWatchers()
{
    return $this->hasMany(VectorRelationshipWatcher::class);
}
```

#### 1.3 ReindexRelatedJob
**Source:** `/packagez/bites/laravel-vector-indexer/src/Jobs/Vector/ReindexRelatedJob.php`
**Target:** `/packages/laravel-ai-engine/src/Jobs/ReindexRelatedJob.php`

**Purpose:** Auto-reindex parent model when relationship changes

**Properties:**
- `$configurationId`
- `$parentModelClass`
- `$parentModelId`
- `$relationshipName`

**Logic:**
```php
public function handle()
{
    $config = VectorConfiguration::find($this->configurationId);
    $parentModel = $this->parentModelClass::find($this->parentModelId);
    
    if ($parentModel) {
        // Reindex the parent with updated relationship data
        dispatch(new IndexModelJob($config->id, $this->parentModelClass, $this->parentModelId, 'update'));
    }
}
```

---

### Phase 2: Auto-Indexing & Observers

#### 2.1 DynamicVectorObserver
**Source:** `/packagez/bites/laravel-vector-indexer/src/Observers/DynamicVectorObserver.php`
**Target:** `/packages/laravel-ai-engine/src/Observers/DynamicVectorObserver.php`

**Purpose:** Auto-index models on create/update/delete

**Methods:**
```php
public function created(Model $model)
{
    $this->indexModel($model, 'create');
}

public function updated(Model $model)
{
    // Check if watched fields changed
    if ($this->hasWatchedFieldsChanged($model)) {
        $this->indexModel($model, 'update');
        $this->reindexRelatedModels($model);
    }
}

public function deleted(Model $model)
{
    $this->indexModel($model, 'delete');
}

protected function reindexRelatedModels(Model $model)
{
    // Find all watchers where this model is the related_model
    $watchers = VectorRelationshipWatcher::enabled()
        ->forRelatedModel(get_class($model))
        ->get();
    
    foreach ($watchers as $watcher) {
        // Dispatch job to reindex parent
        dispatch(new ReindexRelatedJob(...));
    }
}
```

#### 2.2 VectorObserverManager
**Source:** `/packagez/bites/laravel-vector-indexer/src/Services/Vector/VectorObserverManager.php`
**Target:** `/packages/laravel-ai-engine/src/Services/VectorObserverManager.php`

**Purpose:** Register/unregister observers dynamically

```php
public function registerObserver(string $modelClass)
{
    $modelClass::observe(DynamicVectorObserver::class);
}

public function unregisterObserver(string $modelClass)
{
    // Remove observer
}
```

---

### Phase 3: Schema Analysis & Chunking

#### 3.1 SchemaAnalyzer
**Source:** `/packagez/bites/laravel-vector-indexer/src/Services/Vector/SchemaAnalyzer.php`
**Target:** `/packages/laravel-ai-engine/src/Services/SchemaAnalyzer.php`

**Purpose:** Auto-detect which fields should be indexed

**Methods:**
```php
public function analyzeModel(string $modelClass): array
{
    // Get table columns
    // Identify text fields (string, text, longtext)
    // Identify relationship methods
    // Return recommended configuration
    
    return [
        'text_fields' => ['title', 'content', 'description'],
        'relationships' => ['author', 'tags', 'comments'],
        'recommended_depth' => 2,
    ];
}

public function getIndexableFields(string $modelClass): array
{
    // Return fields suitable for vector indexing
}

public function getRelationships(string $modelClass): array
{
    // Use reflection to find relationship methods
}
```

#### 3.2 ChunkingService
**Source:** `/packagez/bites/laravel-vector-indexer/src/Services/Vector/ChunkingService.php`
**Target:** `/packages/laravel-ai-engine/src/Services/ChunkingService.php`

**Purpose:** Split large text into chunks for indexing

**Methods:**
```php
public function chunkText(string $text, int $maxTokens = 8000): array
{
    // Split text into chunks
    // Preserve sentence boundaries
    // Add overlap between chunks
    
    return [
        ['text' => 'chunk 1...', 'start' => 0, 'end' => 1000],
        ['text' => 'chunk 2...', 'start' => 900, 'end' => 1900],
    ];
}

public function estimateTokens(string $text): int
{
    // Rough estimation: ~4 chars per token
    return (int) ceil(strlen($text) / 4);
}
```

---

### Phase 4: Media Embeddings

#### 4.1 HasMediaEmbeddings Trait
**Source:** `/packagez/bites/laravel-vector-indexer/src/Traits/HasMediaEmbeddings.php`
**Target:** `/packages/laravel-ai-engine/src/Traits/HasMediaEmbeddings.php`

**Purpose:** Index images, videos using GPT-4 Vision

**Methods:**
```php
public function getMediaForIndexing(): array
{
    // Return array of media URLs/paths
    return [
        ['type' => 'image', 'url' => 'https://...'],
        ['type' => 'video', 'url' => 'https://...'],
    ];
}

public function generateImageEmbedding(string $imageUrl): array
{
    // Use GPT-4 Vision to describe image
    // Generate embedding from description
}

public function generateVideoEmbedding(string $videoUrl): array
{
    // Extract frames
    // Generate embeddings for frames
    // Combine with audio transcription
}
```

#### 4.2 HasAudioTranscription Trait
**Source:** `/packagez/bites/laravel-vector-indexer/src/Traits/HasAudioTranscription.php`
**Target:** `/packages/laravel-ai-engine/src/Traits/HasAudioTranscription.php`

**Purpose:** Transcribe audio using Whisper

**Methods:**
```php
public function getAudioForTranscription(): ?string
{
    // Return audio file path/URL
}

public function transcribeAudio(string $audioPath): string
{
    // Use OpenAI Whisper API
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . config('ai-engine.engines.openai.api_key'),
    ])->attach('file', file_get_contents($audioPath), 'audio.mp3')
      ->post('https://api.openai.com/v1/audio/transcriptions', [
          'model' => 'whisper-1',
      ]);
    
    return $response->json('text');
}

public function getTranscription(): ?string
{
    // Return cached transcription
}
```

---

### Phase 5: Enhanced Vectorizable Trait

#### 5.1 Add Relationship Methods to Vectorizable
**Target:** `/packages/laravel-ai-engine/src/Traits/Vectorizable.php`

**New Methods:**
```php
/**
 * Get content with relationships
 */
public function getVectorContentWithRelationships(array $relationships = []): string
{
    $content = [$this->getVectorContent()];
    
    foreach ($relationships as $relation) {
        if ($this->relationLoaded($relation)) {
            $related = $this->$relation;
            
            if ($related instanceof Collection) {
                foreach ($related as $item) {
                    if (method_exists($item, 'getVectorContent')) {
                        $content[] = $item->getVectorContent();
                    }
                }
            } elseif ($related && method_exists($related, 'getVectorContent')) {
                $content[] = $related->getVectorContent();
            }
        }
    }
    
    return implode("\n\n", $content);
}

/**
 * Index this model with relationships
 */
public function indexWithRelationships(array $relationships = []): void
{
    // Load relationships
    $this->load($relationships);
    
    // Index with relationship content
    $content = $this->getVectorContentWithRelationships($relationships);
    
    // Dispatch index job
    dispatch(new IndexModelJob(...));
}

/**
 * Get configured relationships for indexing
 */
public function getIndexableRelationships(): array
{
    $config = $this->getVectorConfiguration();
    return $config?->relationships ?? [];
}
```

---

### Phase 6: Update Commands

#### 6.1 Enhance VectorIndexCommand
**Target:** `/packages/laravel-ai-engine/src/Console/Commands/VectorIndexCommand.php`

**Add options:**
```php
protected $signature = 'ai-engine:vector-index
                        {model? : The model class to index}
                        {--id=* : Specific model IDs to index}
                        {--batch=100 : Batch size for indexing}
                        {--force : Force re-indexing}
                        {--queue : Queue the indexing jobs}
                        {--with-relationships : Include relationships in indexing}
                        {--relationship-depth=2 : Max relationship depth}';
```

**Enhanced indexing:**
```php
protected function indexModel(string $modelClass, VectorSearchService $vectorSearch, bool $showHeader = true): int
{
    // ... existing code ...
    
    if ($this->option('with-relationships')) {
        $depth = (int) $this->option('relationship-depth');
        $this->info("Including relationships (depth: {$depth})");
        
        // Load relationships based on depth
        $relationships = $this->getRelationshipsForDepth($modelClass, $depth);
        
        foreach ($models as $model) {
            $model->load($relationships);
            // Index with relationships
        }
    }
}
```

---

## 🗂️ File Structure After Port

```
packages/laravel-ai-engine/
├── src/
│   ├── Models/
│   │   ├── VectorRelationshipWatcher.php ✅
│   │   └── (update) VectorConfiguration.php
│   ├── Jobs/
│   │   ├── IndexModelJob.php
│   │   └── ReindexRelatedJob.php
│   ├── Observers/
│   │   └── DynamicVectorObserver.php
│   ├── Services/
│   │   ├── SchemaAnalyzer.php
│   │   ├── ChunkingService.php
│   │   └── VectorObserverManager.php
│   ├── Traits/
│   │   ├── Vectorizable.php (enhanced)
│   │   ├── HasMediaEmbeddings.php
│   │   └── HasAudioTranscription.php
│   └── Console/Commands/
│       └── VectorIndexCommand.php (enhanced)
├── database/migrations/
│   └── xxxx_create_vector_relationship_watchers_table.php
└── config/
    └── ai-engine.php (add relationship config)
```

---

## 🧪 Testing Plan

1. **Unit Tests:**
   - VectorRelationshipWatcher model
   - SchemaAnalyzer service
   - ChunkingService

2. **Integration Tests:**
   - Relationship indexing
   - Auto-reindex on relation change
   - Media embedding generation

3. **Feature Tests:**
   - Search with relationships
   - Multi-depth relationship indexing
   - Observer auto-indexing

---

## 📝 Configuration Updates

**Add to `config/ai-engine.php`:**
```php
'vector' => [
    'relationships' => [
        'enabled' => true,
        'max_depth' => 2,
        'auto_watch' => true, // Auto-create watchers
    ],
    
    'chunking' => [
        'enabled' => true,
        'max_tokens' => 8000,
        'overlap' => 100, // Token overlap between chunks
    ],
    
    'media' => [
        'images' => [
            'enabled' => true,
            'use_vision' => true, // GPT-4 Vision
        ],
        'audio' => [
            'enabled' => true,
            'use_whisper' => true,
        ],
        'video' => [
            'enabled' => false, // Premium feature
        ],
    ],
    
    'observers' => [
        'auto_register' => true,
        'auto_index_on_create' => true,
        'auto_index_on_update' => true,
        'auto_delete_on_delete' => true,
    ],
],
```

---

## 🚀 Implementation Order

1. ✅ VectorRelationshipWatcher model + migration
2. Update VectorConfiguration model
3. ReindexRelatedJob
4. DynamicVectorObserver
5. VectorObserverManager
6. SchemaAnalyzer
7. ChunkingService
8. Update Vectorizable trait
9. HasMediaEmbeddings trait
10. HasAudioTranscription trait
11. Update VectorIndexCommand
12. Add configuration
13. Write tests
14. Update documentation

---

## 📚 Documentation Updates

- Add relationship indexing guide
- Add media embedding guide
- Add auto-indexing guide
- Update README with new features
- Add migration guide from basic to advanced

---

## ⚡ Quick Start After Port

```php
// Enable relationship indexing
use LaravelAIEngine\Traits\Vectorizable;

class Post extends Model
{
    use Vectorizable;
    
    // Define relationships to index
    protected $vectorRelationships = ['author', 'tags', 'comments'];
    protected $maxRelationshipDepth = 2;
    
    public function author() {
        return $this->belongsTo(User::class);
    }
    
    public function tags() {
        return $this->belongsToMany(Tag::class);
    }
    
    public function comments() {
        return $this->hasMany(Comment::class);
    }
}

// Index with relationships
php artisan ai-engine:vector-index "App\Models\Post" --with-relationships

// Search includes relationship content
$posts = Post::vectorSearch('Laravel expert author');
```

---

**Status:** Ready for implementation
**Estimated Time:** 8-12 hours for full port
**Priority:** High - These features significantly enhance the package
