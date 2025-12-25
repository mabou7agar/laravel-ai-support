# Large Media File Processing

The Laravel AI Engine can process large media files (videos, audio) by splitting them into chunks.

---

## Configuration

### Enable Large Media Processing

```env
# Enable processing of large media files
AI_ENGINE_PROCESS_LARGE_MEDIA=true

# Maximum file size to download (default: 10MB)
AI_ENGINE_MAX_MEDIA_FILE_SIZE=10485760

# Chunk duration for video/audio (in seconds)
AI_ENGINE_MEDIA_CHUNK_DURATION=60

# Maximum extracted content size
AI_ENGINE_MAX_MEDIA_CONTENT=50000
```

```php
'vectorization' => [
    // Enable large media processing
    'process_large_media' => true,
    
    // Max file size (10MB default)
    'max_media_file_size' => 10485760,
    
    // Chunk duration (60 seconds)
    'media_chunk_duration' => 60,
    
    // Max extracted content
    'max_media_content' => 50000,
],
```

---

## How It Works

### Without Large Media Processing (Default)

```
Video: 50MB
→ Check size: 50MB > 10MB
→ ❌ Skip download
→ Log: "Media file too large, skipping"
→ Continue with text only
```

### With Large Media Processing (Enabled)

```
Video: 50MB
→ Check size: 50MB > 10MB
→ ✅ Download (chunked processing enabled)
→ Split into 60-second chunks
→ Process each chunk:
   - Chunk 1 (0-60s): Extract/transcribe
   - Chunk 2 (60-120s): Extract/transcribe
   - Chunk 3 (120-180s): Extract/transcribe
   ...
→ Combine all chunks
→ Use combined content
```

---

## Use Cases

### 1. Long Video Processing

**Scenario:** 30-minute product demo video (100MB)

**Without chunking:**
```
❌ File too large, skipped
Result: No video content indexed
```

**With chunking:**
```
✅ Downloaded and processed
Chunks: 30 chunks (60s each)
Result: Full video content indexed
Search: "product demo feature X" ✅ Found
```

### 2. Podcast Transcription

**Scenario:** 1-hour podcast (80MB)

**Without chunking:**
```
❌ File too large, skipped
Result: No audio content indexed
```

**With chunking:**
```
✅ Downloaded and processed
Chunks: 60 chunks (60s each)
Result: Full podcast transcribed
Search: "discussion about topic Y" ✅ Found
```

### 3. Webinar Recording

**Scenario:** 2-hour webinar (200MB)

**Without chunking:**
```
❌ File too large, skipped
Result: No webinar content indexed
```

**With chunking:**
```
✅ Downloaded and processed
Chunks: 120 chunks (60s each)
Result: Full webinar indexed
Search: "Q&A session question" ✅ Found
```

---

## Processing Flow

### Step 1: Check Configuration

```php
$processLargeMedia = config('ai-engine.vectorization.process_large_media');
// true = process large files
// false = skip large files (default)
```

### Step 2: Download Decision

```php
if ($fileSize > $maxFileSize) {
    if ($processLargeMedia && in_array($type, ['video', 'audio'])) {
        // Download for chunked processing
        downloadFile($url);
    } else {
        // Skip download
        return null;
    }
}
```

### Step 3: Chunk Processing

```php
// Split video into 60-second chunks
$chunks = [];
for ($i = 0; $i < $duration; $i += 60) {
    $chunk = extractChunk($video, $i, 60);
    $chunks[] = processChunk($chunk);
}

// Combine results
$content = implode(' ', $chunks);
```

### Step 4: Combine & Use

```php
// All chunks combined
$vectorContent = $textContent . ' ' . $mediaContent;

// Create embedding
$embedding = embed($vectorContent);
```

---

## Example Usage

### Model Setup

```php
use LaravelAIEngine\Traits\VectorizableWithMedia;

class Webinar extends Model
{
    use VectorizableWithMedia;
    
    // Auto-detects video_url field
}
```

### Create with Large Video

```php
$webinar = Webinar::create([
    'title' => '2-Hour Product Training',
    'description' => 'Comprehensive product overview',
    'video_url' => 'https://example.com/webinar.mp4', // 200MB
]);

// With process_large_media = false (default):
// → Video skipped
// → Only title + description indexed

// With process_large_media = true:
// → Video downloaded
// → Split into 120 chunks (60s each)
// → All chunks processed
// → Full content indexed
```

### Search Results

```php
// Search for content in the video
$results = VectorSearch::search('product feature demonstration');

// Without chunking:
// → No results (video was skipped)

// With chunking:
// → Found: Webinar at timestamp 45:30
// → Context: "...product feature demonstration shows..."
```

---

## Performance Considerations

### Processing Time

**Small file (5MB):**
- Download: 2s
- Process: 5s
- Total: 7s

**Large file (100MB) without chunking:**
- Download: Skipped
- Process: Skipped
- Total: 0s (but no content)

**Large file (100MB) with chunking:**
- Download: 30s
- Process: 120s (60 chunks × 2s each)
- Total: 150s (but full content indexed)

### Cost Considerations

**API Costs:**
- Vision API: ~$0.01 per image
- Transcription: ~$0.006 per minute
- Embeddings: ~$0.0001 per 1K tokens

**Example: 1-hour video (60 chunks)**
- Transcription: 60 min × $0.006 = $0.36
- Embeddings: ~$0.01
- Total: ~$0.37 per video

### Storage

**Embeddings:**
- 1 chunk = 1 embedding vector
- 60 chunks = 60 vectors
- Storage: 60 × 1536 dimensions × 4 bytes = ~370KB

---

## Logging

### Large File Download

```json
{
  "message": "Downloading large media file for chunked processing",
  "url": "https://example.com/video.mp4",
  "file_size": 104857600,
  "size_mb": "100MB"
}
```

### Chunked Processing

```json
{
  "message": "Processed large media in chunks",
  "url": "https://example.com/video.mp4",
  "type": "video",
  "chunk_count": 60,
  "chunk_duration": 60,
  "total_content_length": 45000
}
```

### Processing Complete

```json
{
  "message": "Processed URL media",
  "url": "https://example.com/video.mp4",
  "type": "video",
  "file_size": 104857600,
  "content_length": 45000,
  "was_chunked": true
}
```

---

## Comparison

| Feature | Without Chunking | With Chunking |
|---------|------------------|---------------|
| Max file size | 10MB | Unlimited |
| Processing time | Fast | Slower |
| Content coverage | Partial | Complete |
| API cost | Low | Higher |
| Search accuracy | Limited | Comprehensive |
| Use case | Small files | Large files |

---

## Best Practices

### When to Enable

✅ **Enable when:**
- Processing webinars/podcasts
- Long video content
- Comprehensive search needed
- Budget allows

❌ **Disable when:**
- Only small files
- Cost-sensitive
- Speed is critical
- Simple use cases

### Optimization Tips

**1. Adjust Chunk Duration**
```env
# Longer chunks = fewer API calls
AI_ENGINE_MEDIA_CHUNK_DURATION=120  # 2 minutes

# Shorter chunks = better granularity
AI_ENGINE_MEDIA_CHUNK_DURATION=30   # 30 seconds
```

**2. Set File Size Limit**
```env
# Allow larger files
AI_ENGINE_MAX_MEDIA_FILE_SIZE=52428800  # 50MB

# Stricter limit
AI_ENGINE_MAX_MEDIA_FILE_SIZE=5242880   # 5MB
```

**3. Content Truncation**
```env
# More content per chunk
AI_ENGINE_MAX_MEDIA_CONTENT=100000  # 100KB

# Less content per chunk
AI_ENGINE_MAX_MEDIA_CONTENT=25000   # 25KB
```

---

## Requirements

### FFmpeg (Optional)

For advanced video/audio chunking:

```bash
# Install FFmpeg
brew install ffmpeg  # macOS
apt-get install ffmpeg  # Ubuntu
```

### PHP Extensions

```bash
# Required
php -m | grep fileinfo
php -m | grep curl
```

---

## Status

✅ **Configuration** - Complete  
✅ **Download Logic** - Complete  
✅ **Chunk Detection** - Complete  
⚠️ **FFmpeg Integration** - Simplified (can be enhanced)  
✅ **Logging** - Complete  

**Production Ready with basic chunking** ✅

**Enhanced FFmpeg chunking** - Available for custom implementation
