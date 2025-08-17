<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\UploadedFile;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;

class StorageManager
{
    private string $defaultDisk;
    private array $supportedFormats;

    public function __construct()
    {
        $this->defaultDisk = Config::get('ai-engine.storage.default_disk', 'local');
        $this->supportedFormats = Config::get('ai-engine.storage.supported_formats', [
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            'audio' => ['mp3', 'wav', 'ogg', 'aac', 'm4a'],
            'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'],
            'documents' => ['pdf', 'doc', 'docx', 'txt', 'rtf'],
            'data' => ['csv', 'json', 'xml', 'xlsx'],
        ]);
    }

    /**
     * Store AI-generated content
     */
    public function storeAIContent(AIResponse $response, array $options = []): array
    {
        $storedFiles = [];
        $contentType = $options['content_type'] ?? 'text';
        $disk = $options['disk'] ?? $this->defaultDisk;
        $folder = $options['folder'] ?? $this->getDefaultFolder($contentType);

        // Store main content if it's text
        if ($contentType === 'text' && !empty($response->content)) {
            $textFile = $this->storeTextContent($response->content, $disk, $folder, $options);
            $storedFiles['content'] = $textFile;
        }

        // Store generated files (images, audio, etc.)
        if (!empty($response->files)) {
            $storedFiles['files'] = [];
            foreach ($response->files as $index => $fileUrl) {
                $storedFile = $this->storeFileFromUrl($fileUrl, $disk, $folder, $options);
                $storedFiles['files'][$index] = $storedFile;
            }
        }

        // Store metadata
        $metadata = [
            'engine' => $response->engine->value,
            'model' => $response->model->value,
            'tokens_used' => $response->tokensUsed,
            'credits_used' => $response->creditsUsed,
            'request_id' => $response->requestId,
            'created_at' => now()->toISOString(),
            'content_type' => $contentType,
            'detailed_usage' => $response->detailedUsage,
        ];

        $metadataFile = $this->storeMetadata($metadata, $disk, $folder);
        $storedFiles['metadata'] = $metadataFile;

        return $storedFiles;
    }

    /**
     * Store uploaded file with optimization
     */
    public function storeUploadedFile(UploadedFile $file, array $options = []): array
    {
        $disk = $options['disk'] ?? $this->defaultDisk;
        $folder = $options['folder'] ?? 'uploads';
        $optimize = $options['optimize'] ?? true;

        // Validate file
        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException($validation['error']);
        }

        // Generate unique filename
        $filename = $this->generateUniqueFilename($file, $options);
        $path = $folder . '/' . $filename;

        // Optimize file if needed
        if ($optimize) {
            $optimizedFile = $this->optimizeFile($file);
            $storedPath = Storage::disk($disk)->putFileAs($folder, $optimizedFile, $filename);
        } else {
            $storedPath = Storage::disk($disk)->putFileAs($folder, $file, $filename);
        }

        return [
            'path' => $storedPath,
            'url' => Storage::disk($disk)->url($storedPath),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'disk' => $disk,
            'optimized' => $optimize,
            'stored_at' => now()->toISOString(),
        ];
    }

    /**
     * Store file from URL
     */
    public function storeFileFromUrl(string $url, string $disk = null, string $folder = null, array $options = []): array
    {
        $disk = $disk ?? $this->defaultDisk;
        $folder = $folder ?? 'ai-generated';

        try {
            // Download file content
            $content = file_get_contents($url);
            if ($content === false) {
                throw new \RuntimeException("Failed to download file from URL: {$url}");
            }

            // Determine file extension from URL or content type
            $extension = $this->getExtensionFromUrl($url);
            $filename = uniqid('ai_file_') . '.' . $extension;
            $path = $folder . '/' . $filename;

            // Store file
            $stored = Storage::disk($disk)->put($path, $content);
            if (!$stored) {
                throw new \RuntimeException("Failed to store file");
            }

            return [
                'path' => $path,
                'url' => Storage::disk($disk)->url($path),
                'size' => strlen($content),
                'original_url' => $url,
                'extension' => $extension,
                'disk' => $disk,
                'stored_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            throw new \RuntimeException("Error storing file from URL: " . $e->getMessage());
        }
    }

    /**
     * Store text content as file
     */
    public function storeTextContent(string $content, string $disk = null, string $folder = null, array $options = []): array
    {
        $disk = $disk ?? $this->defaultDisk;
        $folder = $folder ?? 'ai-text';
        $format = $options['format'] ?? 'txt';

        $filename = uniqid('ai_text_') . '.' . $format;
        $path = $folder . '/' . $filename;

        // Format content based on type
        $formattedContent = $this->formatTextContent($content, $format, $options);

        $stored = Storage::disk($disk)->put($path, $formattedContent);
        if (!$stored) {
            throw new \RuntimeException("Failed to store text content");
        }

        return [
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'size' => strlen($formattedContent),
            'format' => $format,
            'disk' => $disk,
            'word_count' => str_word_count($content),
            'character_count' => strlen($content),
            'stored_at' => now()->toISOString(),
        ];
    }

    /**
     * Store metadata as JSON
     */
    public function storeMetadata(array $metadata, string $disk = null, string $folder = null): array
    {
        $disk = $disk ?? $this->defaultDisk;
        $folder = $folder ?? 'ai-metadata';

        $filename = uniqid('metadata_') . '.json';
        $path = $folder . '/' . $filename;

        $jsonContent = json_encode($metadata, JSON_PRETTY_PRINT);
        $stored = Storage::disk($disk)->put($path, $jsonContent);

        if (!$stored) {
            throw new \RuntimeException("Failed to store metadata");
        }

        return [
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'size' => strlen($jsonContent),
            'disk' => $disk,
            'stored_at' => now()->toISOString(),
        ];
    }

    /**
     * Get file information
     */
    public function getFileInfo(string $path, string $disk = null): array
    {
        $disk = $disk ?? $this->defaultDisk;
        $storage = Storage::disk($disk);

        if (!$storage->exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        return [
            'path' => $path,
            'url' => $storage->url($path),
            'size' => $storage->size($path),
            'last_modified' => $storage->lastModified($path),
            'mime_type' => $storage->mimeType($path),
            'exists' => true,
            'disk' => $disk,
        ];
    }

    /**
     * Delete file
     */
    public function deleteFile(string $path, string $disk = null): bool
    {
        $disk = $disk ?? $this->defaultDisk;
        return Storage::disk($disk)->delete($path);
    }

    /**
     * Copy file between disks
     */
    public function copyFile(string $sourcePath, string $destinationPath, string $sourceDisk = null, string $destinationDisk = null): bool
    {
        $sourceDisk = $sourceDisk ?? $this->defaultDisk;
        $destinationDisk = $destinationDisk ?? $this->defaultDisk;

        $content = Storage::disk($sourceDisk)->get($sourcePath);
        return Storage::disk($destinationDisk)->put($destinationPath, $content);
    }

    /**
     * Get storage usage statistics
     */
    public function getStorageStats(string $disk = null): array
    {
        $disk = $disk ?? $this->defaultDisk;
        $storage = Storage::disk($disk);

        $totalSize = 0;
        $fileCount = 0;
        $folders = ['ai-generated', 'ai-text', 'ai-metadata', 'uploads'];

        $stats = [
            'disk' => $disk,
            'folders' => [],
            'total_size' => 0,
            'total_files' => 0,
        ];

        foreach ($folders as $folder) {
            if ($storage->exists($folder)) {
                $files = $storage->allFiles($folder);
                $folderSize = 0;

                foreach ($files as $file) {
                    $folderSize += $storage->size($file);
                }

                $stats['folders'][$folder] = [
                    'file_count' => count($files),
                    'size' => $folderSize,
                    'size_human' => $this->formatBytes($folderSize),
                ];

                $totalSize += $folderSize;
                $fileCount += count($files);
            }
        }

        $stats['total_size'] = $totalSize;
        $stats['total_files'] = $fileCount;
        $stats['total_size_human'] = $this->formatBytes($totalSize);

        return $stats;
    }

    /**
     * Clean up old files
     */
    public function cleanupOldFiles(int $daysOld = 30, string $disk = null): array
    {
        $disk = $disk ?? $this->defaultDisk;
        $storage = Storage::disk($disk);
        $cutoffTime = now()->subDays($daysOld)->timestamp;

        $deletedFiles = [];
        $folders = ['ai-generated', 'ai-text', 'ai-metadata', 'uploads'];

        foreach ($folders as $folder) {
            if ($storage->exists($folder)) {
                $files = $storage->allFiles($folder);

                foreach ($files as $file) {
                    if ($storage->lastModified($file) < $cutoffTime) {
                        if ($storage->delete($file)) {
                            $deletedFiles[] = $file;
                        }
                    }
                }
            }
        }

        return [
            'deleted_count' => count($deletedFiles),
            'deleted_files' => $deletedFiles,
            'cutoff_date' => now()->subDays($daysOld)->toISOString(),
        ];
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $maxSize = Config::get('ai-engine.storage.max_file_size', 10 * 1024 * 1024); // 10MB default

        // Check file size
        if ($file->getSize() > $maxSize) {
            return [
                'valid' => false,
                'error' => "File size exceeds maximum allowed size of " . $this->formatBytes($maxSize),
            ];
        }

        // Check file extension
        $allSupportedFormats = array_merge(...array_values($this->supportedFormats));
        if (!in_array($extension, $allSupportedFormats)) {
            return [
                'valid' => false,
                'error' => "File format '{$extension}' is not supported",
            ];
        }

        return ['valid' => true];
    }

    /**
     * Generate unique filename
     */
    private function generateUniqueFilename(UploadedFile $file, array $options = []): string
    {
        $prefix = $options['prefix'] ?? 'file';
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        $random = substr(uniqid(), -6);

        return "{$prefix}_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Optimize file (placeholder for actual optimization logic)
     */
    private function optimizeFile(UploadedFile $file): UploadedFile
    {
        // In a real implementation, this would:
        // - Compress images
        // - Convert to optimal formats
        // - Resize if needed
        // - Optimize audio/video files

        return $file; // Return original for now
    }

    /**
     * Get file extension from URL
     */
    private function getExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension ?: 'bin'; // Default to binary if no extension
    }

    /**
     * Format text content based on format
     */
    private function formatTextContent(string $content, string $format, array $options = []): string
    {
        return match ($format) {
            'html' => $this->formatAsHtml($content, $options),
            'markdown', 'md' => $this->formatAsMarkdown($content, $options),
            'json' => json_encode(['content' => $content, 'metadata' => $options]),
            default => $content, // Plain text
        };
    }

    /**
     * Format content as HTML
     */
    private function formatAsHtml(string $content, array $options = []): string
    {
        $title = $options['title'] ?? 'AI Generated Content';
        $style = $options['style'] ?? 'body { font-family: Arial, sans-serif; line-height: 1.6; margin: 40px; }';

        return "<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <style>{$style}</style>
</head>
<body>
    <h1>{$title}</h1>
    <div class=\"content\">" . nl2br(htmlspecialchars($content)) . "</div>
    <footer>
        <p><small>Generated on " . now()->toDateTimeString() . "</small></p>
    </footer>
</body>
</html>";
    }

    /**
     * Format content as Markdown
     */
    private function formatAsMarkdown(string $content, array $options = []): string
    {
        $title = $options['title'] ?? 'AI Generated Content';

        return "# {$title}\n\n{$content}\n\n---\n*Generated on " . now()->toDateTimeString() . "*";
    }

    /**
     * Get default folder for content type
     */
    private function getDefaultFolder(string $contentType): string
    {
        return match ($contentType) {
            'image' => 'ai-images',
            'audio' => 'ai-audio',
            'video' => 'ai-videos',
            'document' => 'ai-documents',
            default => 'ai-generated',
        };
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
