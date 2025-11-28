# Media Embedding Traits - Decision Guide

## 🤔 The Question

**Should we have separate `HasAudioTranscription` trait if we already have `HasMediaEmbeddings`?**

---

## 📊 Analysis

### Option 1: Single `HasMediaEmbeddings` Trait (Recommended)

**One trait handles everything:**
- Images (GPT-4 Vision)
- Documents (PDF, DOCX text extraction)
- Audio (Whisper transcription)
- Video (frames + audio)

```php
trait HasMediaEmbeddings
{
    /**
     * Get media files to embed
     */
    public function getMediaFilesForEmbedding(): array
    {
        return [
            // Images
            ['type' => 'image', 'path' => storage_path('products/' . $this->image)],
            
            // Documents
            ['type' => 'pdf', 'path' => storage_path('docs/' . $this->brochure)],
            
            // Audio
            ['type' => 'audio', 'path' => storage_path('audio/' . $this->podcast)],
            
            // Video
            ['type' => 'video', 'path' => storage_path('videos/' . $this->tutorial)],
        ];
    }
    
    /**
     * Process media and add to vector content
     */
    public function getVectorContent(): string
    {
        $content = parent::getVectorContent();
        
        foreach ($this->getMediaFilesForEmbedding() as $media) {
            $content .= "\n\n" . $this->processMedia($media);
        }
        
        return $content;
    }
    
    protected function processMedia(array $media): string
    {
        return match($media['type']) {
            'image' => $this->processImage($media['path']),
            'pdf' => $this->processPDF($media['path']),
            'audio' => $this->processAudio($media['path']),
            'video' => $this->processVideo($media['path']),
            default => '',
        };
    }
    
    protected function processImage(string $path): string
    {
        // Use GPT-4 Vision to describe image
        return $this->describeImage($path);
    }
    
    protected function processPDF(string $path): string
    {
        // Extract text from PDF
        return $this->extractPDFText($path);
    }
    
    protected function processAudio(string $path): string
    {
        // Transcribe with Whisper
        return $this->transcribeAudio($path);
    }
    
    protected function processVideo(string $path): string
    {
        // Extract audio + key frames
        $audio = $this->extractAudio($path);
        $frames = $this->extractKeyFrames($path);
        
        $transcription = $this->transcribeAudio($audio);
        $frameDescriptions = array_map(fn($f) => $this->describeImage($f), $frames);
        
        return $transcription . "\n\n" . implode("\n", $frameDescriptions);
    }
}
```

**Pros:**
- ✅ Simple - one trait for all media
- ✅ Consistent API
- ✅ Easy to maintain
- ✅ Less code duplication

**Cons:**
- ❌ Larger trait (but still manageable)
- ❌ Includes features user might not need

---

### Option 2: Separate Traits (Not Recommended)

**Multiple specialized traits:**

```php
trait HasImageEmbeddings { }
trait HasDocumentEmbeddings { }
trait HasAudioTranscription { }
trait HasVideoEmbeddings { }
```

**Pros:**
- ✅ Modular
- ✅ User picks what they need

**Cons:**
- ❌ More files to maintain
- ❌ Code duplication
- ❌ Confusing for users
- ❌ Harder to use multiple types

---

## ✅ Recommendation

### Use Single `HasMediaEmbeddings` Trait

**Why:**
1. Simpler for users
2. Less code to maintain
3. Consistent API
4. Easy to extend

**Implementation:**

```php
trait HasMediaEmbeddings
{
    /**
     * Override this to specify media files
     */
    public function getMediaFilesForEmbedding(): array
    {
        return [];
    }
    
    /**
     * Get vector content including media
     */
    public function getVectorContent(): string
    {
        $content = parent::getVectorContent();
        
        // Add media content if any
        $mediaContent = $this->getMediaContent();
        if ($mediaContent) {
            $content .= "\n\n" . $mediaContent;
        }
        
        return $content;
    }
    
    /**
     * Process all media files
     */
    protected function getMediaContent(): string
    {
        $mediaFiles = $this->getMediaFilesForEmbedding();
        
        if (empty($mediaFiles)) {
            return '';
        }
        
        $contents = [];
        
        foreach ($mediaFiles as $media) {
            try {
                $content = $this->processMediaFile($media);
                if ($content) {
                    $contents[] = $content;
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to process media', [
                    'model' => static::class,
                    'media' => $media,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return implode("\n\n", $contents);
    }
    
    /**
     * Process a single media file
     */
    protected function processMediaFile(array $media): string
    {
        $type = $media['type'] ?? $this->detectMediaType($media['path']);
        
        return match($type) {
            'image', 'jpg', 'jpeg', 'png', 'gif', 'webp' => $this->processImage($media['path']),
            'pdf' => $this->processPDF($media['path']),
            'docx', 'doc' => $this->processDocument($media['path']),
            'audio', 'mp3', 'wav', 'ogg', 'm4a' => $this->processAudio($media['path']),
            'video', 'mp4', 'avi', 'mov' => $this->processVideo($media['path']),
            default => '',
        };
    }
    
    /**
     * Detect media type from file extension
     */
    protected function detectMediaType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        return match($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'heic' => 'image',
            'pdf' => 'pdf',
            'doc', 'docx' => 'docx',
            'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac' => 'audio',
            'mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm' => 'video',
            default => 'unknown',
        };
    }
    
    // ===== Image Processing =====
    
    protected function processImage(string $path): string
    {
        if (!config('ai-engine.media.images.enabled', false)) {
            return '';
        }
        
        return $this->describeImageWithVision($path);
    }
    
    protected function describeImageWithVision(string $path): string
    {
        // Use GPT-4 Vision API
        $imageData = base64_encode(file_get_contents($path));
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('ai-engine.engines.openai.api_key'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4-vision-preview',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Describe this image in detail for search purposes. Include objects, colors, text, people, and context.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:image/jpeg;base64,{$imageData}",
                            ],
                        ],
                    ],
                ],
            ],
            'max_tokens' => 500,
        ]);
        
        return $response->json('choices.0.message.content', '');
    }
    
    // ===== PDF Processing =====
    
    protected function processPDF(string $path): string
    {
        if (!config('ai-engine.media.documents.enabled', false)) {
            return '';
        }
        
        // Use pdftotext (requires poppler-utils)
        $output = shell_exec("pdftotext " . escapeshellarg($path) . " -");
        
        return trim($output ?? '');
    }
    
    // ===== Document Processing =====
    
    protected function processDocument(string $path): string
    {
        if (!config('ai-engine.media.documents.enabled', false)) {
            return '';
        }
        
        // Use PHPWord or similar
        // For now, return empty (user can implement)
        return '';
    }
    
    // ===== Audio Processing =====
    
    protected function processAudio(string $path): string
    {
        if (!config('ai-engine.media.audio.enabled', false)) {
            return '';
        }
        
        return $this->transcribeWithWhisper($path);
    }
    
    protected function transcribeWithWhisper(string $path): string
    {
        // Use OpenAI Whisper API
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('ai-engine.engines.openai.api_key'),
        ])->attach('file', file_get_contents($path), basename($path))
          ->post('https://api.openai.com/v1/audio/transcriptions', [
              'model' => 'whisper-1',
          ]);
        
        return $response->json('text', '');
    }
    
    // ===== Video Processing =====
    
    protected function processVideo(string $path): string
    {
        if (!config('ai-engine.media.video.enabled', false)) {
            return '';
        }
        
        // Extract audio
        $audioPath = $this->extractAudioFromVideo($path);
        $transcription = $this->transcribeWithWhisper($audioPath);
        
        // Extract key frames (optional)
        $frames = $this->extractKeyFrames($path);
        $frameDescriptions = [];
        foreach ($frames as $frame) {
            $frameDescriptions[] = $this->describeImageWithVision($frame);
        }
        
        $content = "Audio Transcription:\n" . $transcription;
        
        if (!empty($frameDescriptions)) {
            $content .= "\n\nVisual Content:\n" . implode("\n", $frameDescriptions);
        }
        
        return $content;
    }
    
    protected function extractAudioFromVideo(string $videoPath): string
    {
        // Use ffmpeg to extract audio
        $audioPath = sys_get_temp_dir() . '/' . uniqid() . '.mp3';
        
        shell_exec("ffmpeg -i " . escapeshellarg($videoPath) . " -vn -acodec mp3 " . escapeshellarg($audioPath));
        
        return $audioPath;
    }
    
    protected function extractKeyFrames(string $videoPath, int $count = 5): array
    {
        // Use ffmpeg to extract key frames
        $frames = [];
        $tempDir = sys_get_temp_dir();
        
        for ($i = 0; $i < $count; $i++) {
            $framePath = $tempDir . '/' . uniqid() . '.jpg';
            $timestamp = ($i + 1) * 10; // Every 10 seconds
            
            shell_exec("ffmpeg -i " . escapeshellarg($videoPath) . " -ss {$timestamp} -vframes 1 " . escapeshellarg($framePath));
            
            if (file_exists($framePath)) {
                $frames[] = $framePath;
            }
        }
        
        return $frames;
    }
}
```

---

## 📝 Usage Examples

### Example 1: Product with Images

```php
class Product extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public array $vectorizable = ['name', 'description'];
    
    public function getMediaFilesForEmbedding(): array
    {
        return [
            ['type' => 'image', 'path' => storage_path('products/' . $this->image)],
        ];
    }
}

// Index will include image description
$product->indexVector();
```

---

### Example 2: Podcast with Audio

```php
class Podcast extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public array $vectorizable = ['title', 'description'];
    
    public function getMediaFilesForEmbedding(): array
    {
        return [
            ['type' => 'audio', 'path' => storage_path('podcasts/' . $this->audio_file)],
        ];
    }
}

// Index will include audio transcription
$podcast->indexVector();
```

---

### Example 3: Document with PDF

```php
class Contract extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public array $vectorizable = ['title'];
    
    public function getMediaFilesForEmbedding(): array
    {
        return [
            ['type' => 'pdf', 'path' => storage_path('contracts/' . $this->pdf_file)],
        ];
    }
}

// Index will include PDF text
$contract->indexVector();
```

---

### Example 4: Multiple Media Types

```php
class Course extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public array $vectorizable = ['title', 'description'];
    
    public function getMediaFilesForEmbedding(): array
    {
        $media = [];
        
        // Thumbnail
        if ($this->thumbnail) {
            $media[] = ['type' => 'image', 'path' => storage_path('courses/' . $this->thumbnail)];
        }
        
        // Video lessons
        foreach ($this->lessons as $lesson) {
            if ($lesson->video_file) {
                $media[] = ['type' => 'video', 'path' => storage_path('lessons/' . $lesson->video_file)];
            }
        }
        
        // PDF materials
        if ($this->materials_pdf) {
            $media[] = ['type' => 'pdf', 'path' => storage_path('materials/' . $this->materials_pdf)];
        }
        
        return $media;
    }
}
```

---

## ✅ Final Decision

### Single `HasMediaEmbeddings` Trait

**Include:**
- ✅ Image processing (GPT-4 Vision)
- ✅ PDF text extraction
- ✅ Audio transcription (Whisper)
- ✅ Video processing (audio + frames)
- ✅ Document processing (DOCX, etc.)

**Don't create separate traits:**
- ❌ HasAudioTranscription (included in HasMediaEmbeddings)
- ❌ HasImageEmbeddings (included in HasMediaEmbeddings)
- ❌ HasDocumentEmbeddings (included in HasMediaEmbeddings)

**Effort:** 8 hours (all media types in one trait)

**Priority:** P3 (Document only - users can implement if needed)

**Why P3:** 
- Requires expensive APIs (GPT-4 Vision, Whisper)
- Requires system dependencies (ffmpeg, poppler-utils)
- Not all users need it
- Better to document how to implement

---

## 📋 Updated Task

**Task:** Document HasMediaEmbeddings Implementation (2 hours)
- [ ] Write comprehensive guide
- [ ] Add code examples for all media types
- [ ] Document dependencies (ffmpeg, poppler-utils)
- [ ] Document API costs
- [ ] Add troubleshooting
- [ ] Provide ready-to-use trait code

**Don't implement in core** - provide as documentation/example code that users can copy if needed.
