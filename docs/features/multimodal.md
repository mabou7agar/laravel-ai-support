# Multi-Modal AI Guide

Complete guide to working with images, audio, video, and documents in Laravel AI Engine.

## Table of Contents

- [Image Generation](#image-generation)
- [Image Analysis (Vision)](#image-analysis-vision)
- [Audio Transcription](#audio-transcription)
- [Text-to-Speech](#text-to-speech)
- [Video Generation](#video-generation)
- [Video Analysis](#video-analysis)
- [Document Processing](#document-processing)
- [Media Embeddings](#media-embeddings)

## Image Generation

### DALL-E 3 Generation

```php
use LaravelAIEngine\Facades\AIEngine;

// Generate single image
$response = AIEngine::generateImages(
    prompt: 'A futuristic Laravel logo with neon colors',
    count: 1,
    size: '1024x1024'
);

$imageUrl = $response->files[0];
```

### Multiple Images

```php
$response = AIEngine::generateImages(
    prompt: 'Professional headshot of a software developer',
    count: 4,
    size: '1024x1024',
    quality: 'hd'
);

foreach ($response->files as $url) {
    echo "<img src='{$url}' />";
}
```

### Advanced Options

```php
$response = AIEngine::engine('openai')
    ->model('dall-e-3')
    ->generateImages(
        prompt: 'A serene mountain landscape at sunset',
        options: [
            'size' => '1792x1024', // Wide format
            'quality' => 'hd',
            'style' => 'vivid', // or 'natural'
        ]
    );
```

## Image Analysis (Vision)

### Basic Image Analysis

```php
use LaravelAIEngine\Services\Media\VisionService;

$vision = app(VisionService::class);

$analysis = $vision->analyzeImage(
    imagePath: storage_path('app/photos/product.jpg'),
    prompt: 'What is in this image?'
);

echo $analysis['description'];
```

### Detailed Analysis

```php
$analysis = $vision->analyzeImage(
    imagePath: storage_path('app/photos/document.jpg'),
    prompt: 'Extract all text from this image and identify any logos or brands'
);

echo $analysis['description'];
echo "Tokens used: {$analysis['tokens_used']}";
```

### Multiple Images

```php
$images = [
    storage_path('app/photos/image1.jpg'),
    storage_path('app/photos/image2.jpg'),
    storage_path('app/photos/image3.jpg'),
];

foreach ($images as $imagePath) {
    $analysis = $vision->analyzeImage(
        imagePath: $imagePath,
        prompt: 'Describe this image in detail'
    );
    
    echo "Image: {$imagePath}\n";
    echo "Description: {$analysis['description']}\n\n";
}
```

### Base64 Image Analysis

```php
$base64Image = base64_encode(file_get_contents($imagePath));

$analysis = $vision->analyzeBase64Image(
    base64Image: $base64Image,
    mimeType: 'image/jpeg',
    prompt: 'What objects are visible in this image?'
);
```

## Audio Transcription

### Whisper Transcription

```php
use LaravelAIEngine\Services\Media\AudioService;

$audio = app(AudioService::class);

$transcription = $audio->transcribe(
    audioPath: storage_path('app/audio/meeting.mp3')
);

echo $transcription['text'];
echo "Duration: {$transcription['duration']} seconds";
```

### With Language Detection

```php
$transcription = $audio->transcribe(
    audioPath: storage_path('app/audio/podcast.mp3'),
    options: [
        'language' => 'en', // Optional: specify language
        'temperature' => 0.0, // More accurate
    ]
);
```

### Batch Transcription

```php
$audioFiles = [
    'meeting1.mp3',
    'meeting2.mp3',
    'meeting3.mp3',
];

foreach ($audioFiles as $file) {
    $transcription = $audio->transcribe(
        audioPath: storage_path("app/audio/{$file}")
    );
    
    echo "File: {$file}\n";
    echo "Transcription: {$transcription['text']}\n\n";
}
```

## Text-to-Speech

### Generate Speech

```php
$audio = app(AudioService::class);

$audioFile = $audio->textToSpeech(
    text: 'Hello! Welcome to Laravel AI Engine.',
    voice: 'alloy', // alloy, echo, fable, onyx, nova, shimmer
    outputPath: storage_path('app/audio/greeting.mp3')
);

echo "Audio saved to: {$audioFile}";
```

### Different Voices

```php
$voices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];

foreach ($voices as $voice) {
    $audio->textToSpeech(
        text: 'This is a test of the text to speech system.',
        voice: $voice,
        outputPath: storage_path("app/audio/test_{$voice}.mp3")
    );
}
```

## Video Generation

### Stable Diffusion Video

```php
use LaravelAIEngine\Facades\AIEngine;

$response = AIEngine::engine('stable_diffusion')
    ->generateVideo(
        prompt: 'A cat playing piano in a jazz club',
        duration: 5
    );

echo "Video URL: {$response->files[0]}";
```

### FAL AI Video Generation

```php
$response = AIEngine::engine('fal_ai')
    ->model('fal-ai/fast-svd')
    ->generateVideo(
        prompt: 'Futuristic city at sunset with flying cars',
        options: [
            'motion_bucket_id' => 127,
            'fps' => 24,
            'num_frames' => 120,
        ]
    );

// Download video
$videoUrl = $response->files[0];
file_put_contents(
    storage_path('app/videos/generated.mp4'),
    file_get_contents($videoUrl)
);
```

### Advanced Video Generation

```php
$response = AIEngine::engine('fal_ai')
    ->model('fal-ai/fast-animatediff')
    ->generateVideo(
        prompt: 'A serene mountain landscape with moving clouds',
        options: [
            'num_inference_steps' => 25,
            'guidance_scale' => 7.5,
            'num_frames' => 16,
            'fps' => 8,
        ]
    );
```

## Video Analysis

### Process Video (Audio + Frames)

```php
use LaravelAIEngine\Services\Media\VideoService;

$video = app(VideoService::class);

$analysis = $video->processVideo(
    videoPath: storage_path('app/videos/presentation.mp4'),
    options: [
        'include_audio' => true,
        'include_frames' => true,
        'frame_count' => 5,
    ]
);

echo $analysis;
// Output includes:
// - Audio transcription
// - Visual analysis of key frames
```

### Analyze Specific Aspects

```php
$analysis = $video->analyzeVideo(
    videoPath: storage_path('app/videos/tutorial.mp4'),
    prompt: 'Summarize the main topics covered in this tutorial video'
);

echo $analysis;
```

### Extract Key Frames

```php
$frames = $video->extractFrames(
    videoPath: storage_path('app/videos/movie.mp4'),
    frameCount: 10,
    outputDir: storage_path('app/frames')
);

foreach ($frames as $framePath) {
    echo "Frame: {$framePath}\n";
}
```

### Transcribe Video Audio

```php
$transcription = $video->transcribeVideo(
    videoPath: storage_path('app/videos/interview.mp4')
);

echo $transcription['text'];
```

## Document Processing

### PDF Extraction

```php
use LaravelAIEngine\Services\Media\DocumentService;

$document = app(DocumentService::class);

$text = $document->extractText(
    filePath: storage_path('app/documents/report.pdf')
);

echo $text;
```

### DOCX Processing

```php
$text = $document->extractText(
    filePath: storage_path('app/documents/proposal.docx')
);

echo $text;
```

### Multiple Document Types

```php
$files = [
    'report.pdf',
    'proposal.docx',
    'notes.txt',
];

foreach ($files as $file) {
    $text = $document->extractText(
        filePath: storage_path("app/documents/{$file}")
    );
    
    echo "File: {$file}\n";
    echo "Content length: " . strlen($text) . " characters\n\n";
}
```

### Document Analysis

```php
$text = $document->extractText(
    filePath: storage_path('app/documents/contract.pdf')
);

// Analyze with AI
$analysis = AIEngine::chat(
    "Summarize the key points from this document:\n\n{$text}"
);

echo $analysis;
```

## Media Embeddings

### Using HasMediaEmbeddings Trait

```php
use LaravelAIEngine\Traits\HasMediaEmbeddings;

class Media extends Model
{
    use HasMediaEmbeddings;
    
    protected $fillable = ['file_path', 'type'];
}
```

### Process and Embed Media

```php
$media = Media::create([
    'file_path' => storage_path('app/videos/tutorial.mp4'),
    'type' => 'video',
]);

// Process and generate embedding
$media->processMedia();

// Search similar media
$similar = $media->findSimilarMedia(limit: 5);
```

### Batch Media Processing

```php
$mediaFiles = Media::where('processed', false)->get();

foreach ($mediaFiles as $media) {
    try {
        $media->processMedia();
        $media->update(['processed' => true]);
    } catch (\Exception $e) {
        Log::error("Failed to process media {$media->id}: {$e->getMessage()}");
    }
}
```

## Best Practices

### 1. File Size Limits

```php
// Check file size before processing
$maxSize = 25 * 1024 * 1024; // 25MB

if (filesize($audioPath) > $maxSize) {
    throw new \Exception('Audio file too large');
}
```

### 2. Format Validation

```php
$allowedFormats = ['mp3', 'mp4', 'wav', 'webm'];
$extension = pathinfo($audioPath, PATHINFO_EXTENSION);

if (!in_array($extension, $allowedFormats)) {
    throw new \Exception('Unsupported audio format');
}
```

### 3. Error Handling

```php
try {
    $transcription = $audio->transcribe($audioPath);
} catch (\Exception $e) {
    Log::error('Transcription failed', [
        'file' => $audioPath,
        'error' => $e->getMessage(),
    ]);
    
    // Fallback or retry logic
}
```

### 4. Queue Long Operations

```php
use App\Jobs\ProcessVideoJob;

// Queue video processing
ProcessVideoJob::dispatch($videoPath);
```

### 5. Cache Results

```php
use Illuminate\Support\Facades\Cache;

$cacheKey = 'transcription:' . md5($audioPath);

$transcription = Cache::remember($cacheKey, 3600, function () use ($audio, $audioPath) {
    return $audio->transcribe($audioPath);
});
```

## Configuration

### Media Settings

```php
// config/ai-engine.php

'media' => [
    'vision' => [
        'enabled' => true,
        'max_file_size' => 20 * 1024 * 1024, // 20MB
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],
    
    'audio' => [
        'enabled' => true,
        'max_file_size' => 25 * 1024 * 1024, // 25MB
        'allowed_formats' => ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm'],
    ],
    
    'video' => [
        'enabled' => true,
        'max_file_size' => 100 * 1024 * 1024, // 100MB
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'frame_interval' => 30, // Extract frame every 30 seconds
    ],
    
    'documents' => [
        'enabled' => true,
        'max_file_size' => 50 * 1024 * 1024, // 50MB
        'allowed_formats' => ['pdf', 'docx', 'txt', 'md'],
        'pdftotext_path' => env('PDFTOTEXT_PATH', 'pdftotext'),
    ],
],
```

## Troubleshooting

### FFmpeg Not Found

```bash
# Install FFmpeg
# macOS
brew install ffmpeg

# Ubuntu/Debian
sudo apt-get install ffmpeg

# Set path in .env
FFMPEG_PATH=/usr/local/bin/ffmpeg
```

### pdftotext Not Found

```bash
# Install poppler-utils
# macOS
brew install poppler

# Ubuntu/Debian
sudo apt-get install poppler-utils

# Set path in .env
PDFTOTEXT_PATH=/usr/local/bin/pdftotext
```

### Large File Processing

```php
// Increase PHP limits
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

// Or use queues for large files
if (filesize($videoPath) > 50 * 1024 * 1024) {
    ProcessLargeVideoJob::dispatch($videoPath);
}
```

## Next Steps

- [Vector Search](vector-search.md)
- [RAG Guide](rag.md)
- [API Reference](api-reference.md)
