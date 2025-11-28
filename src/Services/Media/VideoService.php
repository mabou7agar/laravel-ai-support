<?php

namespace LaravelAIEngine\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VideoService
{
    protected AudioService $audioService;
    protected VisionService $visionService;

    public function __construct(
        AudioService $audioService,
        VisionService $visionService
    ) {
        $this->audioService = $audioService;
        $this->visionService = $visionService;
    }

    /**
     * Process video file (extract audio + analyze key frames)
     */
    public function processVideo(
        string $videoPath,
        ?string $userId = null,
        array $options = []
    ): string {
        try {
            if (!file_exists($videoPath)) {
                throw new \InvalidArgumentException("Video file not found: {$videoPath}");
            }

            $tempDir = $this->createTempDirectory();
            $results = [];

            // Extract and transcribe audio
            if ($options['include_audio'] ?? true) {
                $audioTranscription = $this->extractAndTranscribeAudio(
                    $videoPath,
                    $tempDir,
                    $userId
                );
                if ($audioTranscription) {
                    $results[] = "Audio Transcription:\n{$audioTranscription}";
                }
            }

            // Extract and analyze key frames
            if ($options['include_frames'] ?? true) {
                $frameAnalysis = $this->extractAndAnalyzeFrames(
                    $videoPath,
                    $tempDir,
                    $userId,
                    $options['frame_count'] ?? 5
                );
                if ($frameAnalysis) {
                    $results[] = "Visual Content:\n{$frameAnalysis}";
                }
            }

            // Cleanup temp directory
            $this->cleanupTempDirectory($tempDir);

            $content = implode("\n\n", $results);

            Log::info('Video processed successfully', [
                'video_path' => $videoPath,
                'content_length' => strlen($content),
            ]);

            return $content;
        } catch (\Exception $e) {
            Log::error('Video processing failed', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract audio from video and transcribe
     */
    protected function extractAndTranscribeAudio(
        string $videoPath,
        string $tempDir,
        ?string $userId = null
    ): ?string {
        try {
            // Check if FFmpeg is available
            if (!$this->isFFmpegAvailable()) {
                Log::warning('FFmpeg not available, skipping audio extraction');
                return null;
            }

            $audioPath = $tempDir . '/audio.mp3';

            // Extract audio using FFmpeg
            $command = sprintf(
                'ffmpeg -i %s -vn -acodec libmp3lame -q:a 2 %s 2>&1',
                escapeshellarg($videoPath),
                escapeshellarg($audioPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($audioPath)) {
                Log::warning('Audio extraction failed', ['output' => implode("\n", $output)]);
                return null;
            }

            // Transcribe audio
            return $this->audioService->transcribe($audioPath, $userId);
        } catch (\Exception $e) {
            Log::error('Audio extraction and transcription failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract key frames and analyze with Vision
     */
    protected function extractAndAnalyzeFrames(
        string $videoPath,
        string $tempDir,
        ?string $userId = null,
        int $frameCount = 5
    ): ?string {
        try {
            // Check if FFmpeg is available
            if (!$this->isFFmpegAvailable()) {
                Log::warning('FFmpeg not available, skipping frame extraction');
                return null;
            }

            // Get video duration
            $duration = $this->getVideoDuration($videoPath);
            if (!$duration) {
                return null;
            }

            // Calculate frame timestamps
            $interval = $duration / ($frameCount + 1);
            $framePaths = [];

            for ($i = 1; $i <= $frameCount; $i++) {
                $timestamp = $interval * $i;
                $framePath = $tempDir . "/frame_{$i}.jpg";

                // Extract frame at timestamp
                $command = sprintf(
                    'ffmpeg -ss %s -i %s -vframes 1 -q:v 2 %s 2>&1',
                    $timestamp,
                    escapeshellarg($videoPath),
                    escapeshellarg($framePath)
                );

                exec($command, $output, $returnCode);

                if ($returnCode === 0 && file_exists($framePath)) {
                    $framePaths[] = $framePath;
                }
            }

            if (empty($framePaths)) {
                Log::warning('No frames extracted');
                return null;
            }

            // Analyze frames with Vision
            $descriptions = [];
            foreach ($framePaths as $index => $framePath) {
                try {
                    $description = $this->visionService->analyzeImage($framePath, $userId);
                    $descriptions[] = "Frame " . ($index + 1) . ": {$description}";
                } catch (\Exception $e) {
                    Log::warning('Frame analysis failed', [
                        'frame' => $index + 1,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return implode("\n", $descriptions);
        } catch (\Exception $e) {
            Log::error('Frame extraction and analysis failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get video duration in seconds
     */
    protected function getVideoDuration(string $videoPath): ?float
    {
        try {
            $command = sprintf(
                'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
                escapeshellarg($videoPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && !empty($output[0])) {
                return (float) $output[0];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if FFmpeg is available
     */
    protected function isFFmpegAvailable(): bool
    {
        exec('ffmpeg -version 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Create temporary directory
     */
    protected function createTempDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/ai-engine-video-' . uniqid();
        
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        return $tempDir;
    }

    /**
     * Cleanup temporary directory
     */
    protected function cleanupTempDirectory(string $tempDir): void
    {
        try {
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($tempDir);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup temp directory', [
                'dir' => $tempDir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get video metadata
     */
    public function getMetadata(string $videoPath): array
    {
        try {
            $command = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
                escapeshellarg($videoPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                $json = implode('', $output);
                $data = json_decode($json, true);

                return [
                    'duration' => $data['format']['duration'] ?? null,
                    'size' => $data['format']['size'] ?? null,
                    'bit_rate' => $data['format']['bit_rate'] ?? null,
                    'format_name' => $data['format']['format_name'] ?? null,
                    'streams' => $data['streams'] ?? [],
                ];
            }

            return [];
        } catch (\Exception $e) {
            Log::error('Failed to get video metadata', [
                'video_path' => $videoPath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if video file is supported
     */
    public function isSupported(string $extension): bool
    {
        $supported = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v'];
        return in_array(strtolower($extension), $supported);
    }
}
