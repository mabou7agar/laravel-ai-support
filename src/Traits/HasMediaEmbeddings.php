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
     */
    public function getMediaVectorContent(): string
    {
        $content = [];

        // Get media content
        if (!empty($this->mediaFields)) {
            $mediaService = app(MediaEmbeddingService::class);
            
            foreach ($this->mediaFields as $type => $field) {
                if (isset($this->$field)) {
                    $mediaContent = $mediaService->getMediaContent($this, $field);
                    if ($mediaContent) {
                        $content[] = $mediaContent;
                    }
                }
            }
        }

        return implode(' ', $content);
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
