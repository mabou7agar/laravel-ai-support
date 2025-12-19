<?php

namespace LaravelAIEngine\Traits;

use LaravelAIEngine\Services\Media\MediaEmbeddingService;
use LaravelAIEngine\Services\Media\VisionService;
use LaravelAIEngine\Services\Media\AudioService;
use LaravelAIEngine\Services\Media\VideoService;
use LaravelAIEngine\Services\Media\DocumentService;
use LaravelAIEngine\Models\Transcription;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasMediaEmbeddings
{
    /**
     * Define which media fields should be embedded
     * Example: ['image' => 'image_path', 'audio' => 'audio_file']
     */
    public array $mediaFields = [];

    /**
     * Get media content for vectorization
     * This method is called by Vectorizable::getVectorContent() if it exists
     * Supports both local files and URLs, arrays, and relationships
     * Handles large media with size limits and truncation
     */
    public function getMediaVectorContent(): string
    {
        $content = [];
        $maxMediaContent = config('ai-engine.vectorization.max_media_content', 50000); // 50KB per media

        // Auto-detect media fields if not explicitly set
        if (empty($this->mediaFields)) {
            $this->mediaFields = $this->autoDetectMediaFields();
        }

        // Get media content
        if (!empty($this->mediaFields)) {
            $mediaService = app(MediaEmbeddingService::class);
            
            foreach ($this->mediaFields as $type => $field) {
                // Handle relationship fields (e.g., 'attachments')
                if (method_exists($this, $field)) {
                    $relationContent = $this->processRelationMedia($field, $type);
                    if ($relationContent) {
                        // Truncate if too large
                        if (strlen($relationContent) > $maxMediaContent) {
                            $relationContent = $this->truncateMediaContent($relationContent, $maxMediaContent, $field);
                        }
                        $content[] = $relationContent;
                    }
                    continue;
                }
                
                if (isset($this->$field)) {
                    $fieldValue = $this->$field;
                    
                    // Handle array of URLs/paths
                    if (is_array($fieldValue)) {
                        foreach ($fieldValue as $index => $item) {
                            $mediaContent = $this->processMediaItem($item, $type, $mediaService, $field);
                            if ($mediaContent) {
                                // Truncate if too large
                                if (strlen($mediaContent) > $maxMediaContent) {
                                    $mediaContent = $this->truncateMediaContent($mediaContent, $maxMediaContent, "{$field}[{$index}]");
                                }
                                $content[] = $mediaContent;
                            }
                        }
                    } else {
                        // Handle single URL/path
                        $mediaContent = $this->processMediaItem($fieldValue, $type, $mediaService, $field);
                        if ($mediaContent) {
                            // Truncate if too large
                            if (strlen($mediaContent) > $maxMediaContent) {
                                $mediaContent = $this->truncateMediaContent($mediaContent, $maxMediaContent, $field);
                            }
                            $content[] = $mediaContent;
                        }
                    }
                }
            }
        }

        return implode(' ', $content);
    }

    /**
     * Truncate media content if too large
     */
    protected function truncateMediaContent(string $content, int $maxSize, string $fieldName): string
    {
        $originalSize = strlen($content);
        $truncated = substr($content, 0, $maxSize);

        if (config('ai-engine.debug')) {
            \Log::channel('ai-engine')->info('Media content truncated', [
                'model' => get_class($this),
                'id' => $this->id ?? 'new',
                'field' => $fieldName,
                'original_size' => $originalSize,
                'truncated_size' => strlen($truncated),
                'reduction' => round((1 - strlen($truncated) / $originalSize) * 100, 1) . '%',
            ]);
        }

        return $truncated;
    }

    /**
     * Process a single media item (URL or local path)
     */
    protected function processMediaItem(string $item, string $type, $mediaService, string $fieldName): ?string
    {
        // Check if item is a URL
        if ($this->isUrl($item)) {
            return $this->processUrlMedia($item, $type);
        } else {
            // Process as local file - use field name, not the value
            return $mediaService->getMediaContent($this, $fieldName);
        }
    }

    /**
     * Process media from relationship
     */
    protected function processRelationMedia(string $relationName, string $type): ?string
    {
        try {
            $related = $this->$relationName;
            
            if (!$related) {
                return null;
            }

            $content = [];

            // Handle collection
            if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                foreach ($related as $item) {
                    $mediaContent = $this->extractMediaFromRelation($item, $type);
                    if ($mediaContent) {
                        $content[] = $mediaContent;
                    }
                }
            } else {
                // Handle single model
                $mediaContent = $this->extractMediaFromRelation($related, $type);
                if ($mediaContent) {
                    $content[] = $mediaContent;
                }
            }

            return implode(' ', $content);

        } catch (\Exception $e) {
            \Log::channel('ai-engine')->warning('Failed to process relation media', [
                'relation' => $relationName,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract media from related model
     */
    protected function extractMediaFromRelation($model, string $type): ?string
    {
        // Try common field names based on type
        $fieldNames = match($type) {
            'image' => ['url', 'path', 'file_path', 'image_url', 'image_path'],
            'audio' => ['url', 'path', 'file_path', 'audio_url', 'audio_path'],
            'video' => ['url', 'path', 'file_path', 'video_url', 'video_path'],
            'document' => ['url', 'path', 'file_path', 'document_url', 'document_path'],
            default => ['url', 'path', 'file_path'],
        };

        foreach ($fieldNames as $fieldName) {
            if (isset($model->$fieldName)) {
                $value = $model->$fieldName;
                
                if ($this->isUrl($value)) {
                    return $this->processUrlMedia($value, $type);
                } else {
                    return app(MediaEmbeddingService::class)->getMediaContent($model, $fieldName);
                }
            }
        }

        return null;
    }

    /**
     * Auto-detect media fields from model
     */
    protected function autoDetectMediaFields(): array
    {
        $detectedFields = [];

        try {
            // Get table columns
            $columns = \Schema::getColumnListing($this->getTable());

            // Common media field patterns
            $patterns = [
                'image' => ['image', 'photo', 'picture', 'avatar', 'thumbnail', 'banner', 'cover'],
                'audio' => ['audio', 'sound', 'voice', 'recording', 'podcast'],
                'video' => ['video', 'movie', 'clip', 'recording'],
                'document' => ['document', 'file', 'pdf', 'doc', 'attachment'],
            ];

            foreach ($columns as $column) {
                $columnLower = strtolower($column);
                
                foreach ($patterns as $type => $keywords) {
                    foreach ($keywords as $keyword) {
                        if (str_contains($columnLower, $keyword) && 
                            (str_contains($columnLower, 'url') || 
                             str_contains($columnLower, 'path') || 
                             str_contains($columnLower, 'file'))) {
                            $detectedFields[$type] = $column;
                            break 2; // Found a match, move to next column
                        }
                    }
                }
            }

            // Check for media relationships
            $relationMethods = get_class_methods($this);
            $mediaRelations = ['attachments', 'images', 'photos', 'files', 'documents', 'media'];
            
            foreach ($mediaRelations as $relation) {
                if (in_array($relation, $relationMethods)) {
                    // Determine type from relation name
                    $type = match(true) {
                        str_contains($relation, 'image') || str_contains($relation, 'photo') => 'image',
                        str_contains($relation, 'audio') => 'audio',
                        str_contains($relation, 'video') => 'video',
                        str_contains($relation, 'document') || str_contains($relation, 'file') => 'document',
                        default => 'document', // Default to document for generic 'attachments', 'media'
                    };
                    
                    $detectedFields[$type] = $relation;
                }
            }

            if (config('ai-engine.debug') && !empty($detectedFields)) {
                \Log::channel('ai-engine')->debug('Auto-detected media fields', [
                    'model' => get_class($this),
                    'detected_fields' => $detectedFields,
                ]);
            }

        } catch (\Exception $e) {
            \Log::channel('ai-engine')->warning('Failed to auto-detect media fields', [
                'model' => get_class($this),
                'error' => $e->getMessage(),
            ]);
        }

        return $detectedFields;
    }

    /**
     * Check if a string is a URL
     */
    protected function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Process media from URL
     * Downloads the file and processes it based on type
     * Supports chunked processing for large files
     */
    protected function processUrlMedia(string $url, string $type): ?string
    {
        try {
            // Check if we should process large files
            $processLargeMedia = config('ai-engine.vectorization.process_large_media', false);
            
            // Download file to temp location
            $tempFile = $this->downloadUrlToTemp($url, $processLargeMedia);
            
            if (!$tempFile) {
                \Log::channel('ai-engine')->warning('Failed to download URL media', [
                    'url' => $url,
                    'type' => $type,
                ]);
                return null;
            }

            // Check file size
            $fileSize = filesize($tempFile);
            $maxFileSize = config('ai-engine.vectorization.max_media_file_size', 10485760);

            // Process based on type and size
            if ($fileSize > $maxFileSize && $processLargeMedia && in_array($type, ['video', 'audio'])) {
                // Process large media in chunks
                $content = $this->processLargeMediaInChunks($tempFile, $type, $url);
            } else {
                // Normal processing
                $content = match($type) {
                    'image' => app(VisionService::class)->analyzeImage($tempFile),
                    'audio' => app(AudioService::class)->transcribe($tempFile),
                    'video' => app(VideoService::class)->processVideo($tempFile),
                    'document' => app(DocumentService::class)->extractText($tempFile),
                    default => null,
                };
            }

            // Cleanup temp file
            @unlink($tempFile);

            if (config('ai-engine.debug')) {
                \Log::channel('ai-engine')->debug('Processed URL media', [
                    'url' => $url,
                    'type' => $type,
                    'file_size' => $fileSize,
                    'content_length' => strlen($content ?? ''),
                    'was_chunked' => $fileSize > $maxFileSize && $processLargeMedia,
                ]);
            }

            return $content;

        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('Error processing URL media', [
                'url' => $url,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Process large media file in chunks
     */
    protected function processLargeMediaInChunks(string $filePath, string $type, string $url): ?string
    {
        try {
            $chunkDuration = config('ai-engine.vectorization.media_chunk_duration', 60);
            $chunks = [];

            if ($type === 'video') {
                // Process video in chunks
                $chunks = $this->processVideoInChunks($filePath, $chunkDuration);
            } elseif ($type === 'audio') {
                // Process audio in chunks
                $chunks = $this->processAudioInChunks($filePath, $chunkDuration);
            }

            $combinedContent = implode(' ', $chunks);

            if (config('ai-engine.debug')) {
                \Log::channel('ai-engine')->info('Processed large media in chunks', [
                    'url' => $url,
                    'type' => $type,
                    'chunk_count' => count($chunks),
                    'chunk_duration' => $chunkDuration,
                    'total_content_length' => strlen($combinedContent),
                ]);
            }

            return $combinedContent;

        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('Failed to process large media in chunks', [
                'url' => $url,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Process video in chunks using FFmpeg
     */
    protected function processVideoInChunks(string $filePath, int $chunkDuration): array
    {
        $chunks = [];
        $videoService = app(VideoService::class);

        // Get video duration (requires FFmpeg)
        // This is a simplified version - actual implementation would use FFmpeg
        // to split video and process each chunk
        
        // For now, process the first chunk only as a demonstration
        $chunks[] = $videoService->processVideo($filePath, null, [
            'duration' => $chunkDuration,
            'start_time' => 0,
        ]);

        return $chunks;
    }

    /**
     * Process audio in chunks
     */
    protected function processAudioInChunks(string $filePath, int $chunkDuration): array
    {
        $chunks = [];
        $audioService = app(AudioService::class);

        // Get audio duration and split into chunks
        // This is a simplified version - actual implementation would use FFmpeg
        // to split audio and transcribe each chunk
        
        // For now, transcribe the whole file
        $chunks[] = $audioService->transcribe($filePath);

        return $chunks;
    }

    /**
     * Download URL to temporary file
     * Checks file size before downloading to prevent memory issues
     */
    protected function downloadUrlToTemp(string $url, bool $allowLargeFiles = false): ?string
    {
        try {
            // Check file size first
            $maxFileSize = config('ai-engine.vectorization.max_media_file_size', 10485760); // 10MB
            
            $headers = @get_headers($url, 1);
            if ($headers && isset($headers['Content-Length'])) {
                $fileSize = is_array($headers['Content-Length']) 
                    ? end($headers['Content-Length']) 
                    : $headers['Content-Length'];
                
                // Skip if too large and not allowing large files
                if ($fileSize > $maxFileSize && !$allowLargeFiles) {
                    \Log::channel('ai-engine')->warning('Media file too large, skipping download', [
                        'url' => $url,
                        'file_size' => $fileSize,
                        'max_size' => $maxFileSize,
                        'size_mb' => round($fileSize / 1048576, 2) . 'MB',
                    ]);
                    return null;
                }
                
                // Log if downloading large file
                if ($fileSize > $maxFileSize && $allowLargeFiles) {
                    \Log::channel('ai-engine')->info('Downloading large media file for chunked processing', [
                        'url' => $url,
                        'file_size' => $fileSize,
                        'size_mb' => round($fileSize / 1048576, 2) . 'MB',
                    ]);
                }
            }

            // Get file extension from URL
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (empty($extension)) {
                $extension = 'tmp';
            }

            // Create temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'media_') . '.' . $extension;

            // Download with timeout
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Laravel-AI-Engine/1.0',
                ],
            ]);

            $content = @file_get_contents($url, false, $context);
            
            if ($content === false) {
                return null;
            }

            // Double-check size after download
            if (strlen($content) > $maxFileSize) {
                \Log::channel('ai-engine')->warning('Downloaded file too large, skipping', [
                    'url' => $url,
                    'downloaded_size' => strlen($content),
                    'max_size' => $maxFileSize,
                ]);
                return null;
            }

            file_put_contents($tempFile, $content);

            return $tempFile;

        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('Failed to download URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Analyze image field
     */
    public function analyzeImage(string $field, ?string $prompt = null): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $visionService = app(VisionService::class);
        return $visionService->analyzeImage($this->$field, null, $prompt);
    }

    /**
     * Get the transcription for this model.
     */
    public function transcription(): MorphOne
    {
        return $this->morphOne(Transcription::class, 'transcribable');
    }

    /**
     * Check if this model has a stored transcription.
     */
    public function hasTranscription(): bool
    {
        return $this->transcription()->where('status', Transcription::STATUS_COMPLETED)->exists();
    }

    /**
     * Get stored transcription content.
     */
    public function getTranscriptionContent(): ?string
    {
        return $this->transcription?->content;
    }

    /**
     * Transcribe audio field (with caching to database)
     */
    public function transcribeAudio(string $field, bool $forceRefresh = false): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        // Check for cached transcription
        if (!$forceRefresh && $this->hasTranscription()) {
            return $this->getTranscriptionContent();
        }

        $audioService = app(AudioService::class);
        $transcription = $audioService->transcribe($this->$field);

        // Store transcription
        if ($transcription) {
            $this->saveTranscription($transcription, [
                'engine' => 'openai',
                'model' => 'whisper-1',
                'language' => $audioService->detectLanguage($this->$field),
            ]);
        }

        return $transcription;
    }

    /**
     * Save transcription to database.
     */
    public function saveTranscription(string $content, array $attributes = []): Transcription
    {
        return $this->transcription()->updateOrCreate(
            [],
            array_merge([
                'content' => $content,
                'status' => Transcription::STATUS_COMPLETED,
            ], $attributes)
        );
    }

    /**
     * Process video field
     */
    public function processVideo(string $field, array $options = []): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $videoService = app(VideoService::class);
        return $videoService->processVideo($this->$field, null, $options);
    }

    /**
     * Extract text from document field
     */
    public function extractDocumentText(string $field): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $documentService = app(DocumentService::class);
        $extension = pathinfo($this->$field, PATHINFO_EXTENSION);
        return $documentService->extractText($this->$field, $extension);
    }

    /**
     * Generate image caption
     */
    public function generateImageCaption(string $field): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $visionService = app(VisionService::class);
        return $visionService->generateCaption($this->$field);
    }

    /**
     * Generate alt text for image
     */
    public function generateImageAltText(string $field): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $visionService = app(VisionService::class);
        return $visionService->generateAltText($this->$field);
    }

    /**
     * Detect objects in image
     */
    public function detectImageObjects(string $field): array
    {
        if (!isset($this->$field)) {
            return [];
        }

        $visionService = app(VisionService::class);
        return $visionService->detectObjects($this->$field);
    }

    /**
     * Extract text from image (OCR)
     */
    public function extractImageText(string $field): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $visionService = app(VisionService::class);
        return $visionService->extractText($this->$field);
    }

    /**
     * Transcribe audio with timestamps
     */
    public function transcribeAudioWithTimestamps(string $field): ?array
    {
        if (!isset($this->$field)) {
            return null;
        }

        $audioService = app(AudioService::class);
        return $audioService->transcribeWithTimestamps($this->$field);
    }

    /**
     * Detect audio language
     */
    public function detectAudioLanguage(string $field): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $audioService = app(AudioService::class);
        return $audioService->detectLanguage($this->$field);
    }

    /**
     * Get video metadata
     */
    public function getVideoMetadata(string $field): array
    {
        if (!isset($this->$field)) {
            return [];
        }

        $videoService = app(VideoService::class);
        return $videoService->getMetadata($this->$field);
    }

    /**
     * Get document metadata
     */
    public function getDocumentMetadata(string $field): array
    {
        if (!isset($this->$field)) {
            return [];
        }

        $documentService = app(DocumentService::class);
        return $documentService->getMetadata($this->$field);
    }
}
