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
     * Supports both local files and URLs
     */
    public function getMediaVectorContent(): string
    {
        $content = [];

        // Get media content
        if (!empty($this->mediaFields)) {
            $mediaService = app(MediaEmbeddingService::class);
            
            foreach ($this->mediaFields as $type => $field) {
                if (isset($this->$field)) {
                    $fieldValue = $this->$field;
                    
                    // Check if field contains a URL
                    if ($this->isUrl($fieldValue)) {
                        $mediaContent = $this->processUrlMedia($fieldValue, $type);
                    } else {
                        // Process as local file
                        $mediaContent = $mediaService->getMediaContent($this, $field);
                    }
                    
                    if ($mediaContent) {
                        $content[] = $mediaContent;
                    }
                }
            }
        }

        return implode(' ', $content);
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
