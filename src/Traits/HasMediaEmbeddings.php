<?php

namespace LaravelAIEngine\Traits;

use LaravelAIEngine\Services\Media\MediaEmbeddingService;
use LaravelAIEngine\Services\Media\VisionService;
use LaravelAIEngine\Services\Media\AudioService;
use LaravelAIEngine\Services\Media\VideoService;
use LaravelAIEngine\Services\Media\DocumentService;

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
     */
    public function getMediaVectorContent(): string
    {
        $content = [];

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
                        $content[] = $relationContent;
                    }
                    continue;
                }
                
                if (isset($this->$field)) {
                    $fieldValue = $this->$field;
                    
                    // Handle array of URLs/paths
                    if (is_array($fieldValue)) {
                        foreach ($fieldValue as $item) {
                            $mediaContent = $this->processMediaItem($item, $type, $mediaService);
                            if ($mediaContent) {
                                $content[] = $mediaContent;
                            }
                        }
                    } else {
                        // Handle single URL/path
                        $mediaContent = $this->processMediaItem($fieldValue, $type, $mediaService);
                        if ($mediaContent) {
                            $content[] = $mediaContent;
                        }
                    }
                }
            }
        }

        return implode(' ', $content);
    }

    /**
     * Process a single media item (URL or local path)
     */
    protected function processMediaItem(string $item, string $type, $mediaService): ?string
    {
        // Check if item is a URL
        if ($this->isUrl($item)) {
            return $this->processUrlMedia($item, $type);
        } else {
            // Process as local file
            return $mediaService->getMediaContent($this, $item);
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
     */
    protected function processUrlMedia(string $url, string $type): ?string
    {
        try {
            // Download file to temp location
            $tempFile = $this->downloadUrlToTemp($url);
            
            if (!$tempFile) {
                \Log::channel('ai-engine')->warning('Failed to download URL media', [
                    'url' => $url,
                    'type' => $type,
                ]);
                return null;
            }

            // Process based on type
            $content = match($type) {
                'image' => app(VisionService::class)->analyzeImage($tempFile),
                'audio' => app(AudioService::class)->transcribe($tempFile),
                'video' => app(VideoService::class)->processVideo($tempFile),
                'document' => app(DocumentService::class)->extractText($tempFile),
                default => null,
            };

            // Cleanup temp file
            @unlink($tempFile);

            if (config('ai-engine.debug')) {
                \Log::channel('ai-engine')->debug('Processed URL media', [
                    'url' => $url,
                    'type' => $type,
                    'content_length' => strlen($content ?? ''),
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
     * Download URL to temporary file
     */
    protected function downloadUrlToTemp(string $url): ?string
    {
        try {
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
     * Transcribe audio field
     */
    public function transcribeAudio(string $field): ?string
    {
        if (!isset($this->$field)) {
            return null;
        }

        $audioService = app(AudioService::class);
        return $audioService->transcribe($this->$field);
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
