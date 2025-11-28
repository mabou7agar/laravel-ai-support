<?php

namespace LaravelAIEngine\Services\Media;

use LaravelAIEngine\Services\Vector\EmbeddingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class MediaEmbeddingService
{
    protected EmbeddingService $embeddingService;
    protected array $config;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
        $this->config = config('ai-engine.vector.media', []);
    }

    /**
     * Process and embed media file
     */
    public function embedMedia(
        string|UploadedFile $file,
        string $type,
        ?string $userId = null
    ): array {
        try {
            // Get file path
            $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
            $extension = $file instanceof UploadedFile ? $file->getClientOriginalExtension() : pathinfo($file, PATHINFO_EXTENSION);

            // Validate file type
            if (!$this->isSupported($extension, $type)) {
                throw new \InvalidArgumentException("Unsupported {$type} format: {$extension}");
            }

            // Extract content based on type
            $content = match ($type) {
                'image' => $this->processImage($filePath, $userId),
                'audio' => $this->processAudio($filePath, $userId),
                'video' => $this->processVideo($filePath, $userId),
                'document' => $this->processDocument($filePath, $extension),
                default => throw new \InvalidArgumentException("Unknown media type: {$type}"),
            };

            // Generate embedding from extracted content
            $embedding = $this->embeddingService->embed($content, $userId);

            return [
                'embedding' => $embedding,
                'content' => $content,
                'type' => $type,
                'extension' => $extension,
                'metadata' => [
                    'file_size' => filesize($filePath),
                    'processed_at' => now()->toIso8601String(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Media embedding failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process image using GPT-4 Vision
     */
    protected function processImage(string $filePath, ?string $userId = null): string
    {
        $visionService = app(\LaravelAIEngine\Services\Media\VisionService::class);
        return $visionService->analyzeImage($filePath, $userId);
    }

    /**
     * Process audio using Whisper
     */
    protected function processAudio(string $filePath, ?string $userId = null): string
    {
        $audioService = app(\LaravelAIEngine\Services\Media\AudioService::class);
        return $audioService->transcribe($filePath, $userId);
    }

    /**
     * Process video (extract audio + key frames)
     */
    protected function processVideo(string $filePath, ?string $userId = null): string
    {
        $videoService = app(\LaravelAIEngine\Services\Media\VideoService::class);
        return $videoService->processVideo($filePath, $userId);
    }

    /**
     * Process document (PDF, DOCX, etc.)
     */
    protected function processDocument(string $filePath, string $extension): string
    {
        $documentService = app(\LaravelAIEngine\Services\Media\DocumentService::class);
        return $documentService->extractText($filePath, $extension);
    }

    /**
     * Check if file type is supported
     */
    public function isSupported(string $extension, string $type): bool
    {
        $supported = $this->config['supported_formats'][$type . 's'] ?? [];
        return in_array(strtolower($extension), $supported);
    }

    /**
     * Get supported formats for a type
     */
    public function getSupportedFormats(string $type): array
    {
        return $this->config['supported_formats'][$type . 's'] ?? [];
    }

    /**
     * Detect media type from extension
     */
    public function detectType(string $extension): ?string
    {
        $extension = strtolower($extension);
        
        foreach ($this->config['supported_formats'] as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return rtrim($type, 's'); // Remove plural 's'
            }
        }

        return null;
    }

    /**
     * Batch process media files
     */
    public function embedMediaBatch(array $files, ?string $userId = null): array
    {
        $results = [];

        foreach ($files as $index => $fileData) {
            try {
                $file = $fileData['file'];
                $type = $fileData['type'] ?? $this->detectType(
                    $file instanceof UploadedFile 
                        ? $file->getClientOriginalExtension() 
                        : pathinfo($file, PATHINFO_EXTENSION)
                );

                if (!$type) {
                    Log::warning('Could not detect media type', ['file' => $file]);
                    continue;
                }

                $results[$index] = $this->embedMedia($file, $type, $userId);
            } catch (\Exception $e) {
                Log::error('Batch media embedding failed for item', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = [
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Get media content for indexing
     */
    public function getMediaContent($model, string $field): ?string
    {
        if (!isset($model->$field)) {
            return null;
        }

        $filePath = $model->$field;
        
        // Handle Storage disk paths
        if (Storage::exists($filePath)) {
            $filePath = Storage::path($filePath);
        }

        if (!file_exists($filePath)) {
            Log::warning('Media file not found', ['path' => $filePath]);
            return null;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $type = $this->detectType($extension);

        if (!$type) {
            return null;
        }

        try {
            $result = $this->embedMedia($filePath, $type);
            return $result['content'];
        } catch (\Exception $e) {
            Log::error('Failed to get media content', [
                'model' => get_class($model),
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
