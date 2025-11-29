# Media Processing Setup Guide

## ✅ Media Processing IS Included!

The Laravel AI Engine package includes **full media processing capabilities** out of the box!

---

## 📦 Included Services

### 1. **VideoService** ✅
- Extracts audio from video
- Transcribes audio with Whisper
- Analyzes key frames with Vision
- Supports: MP4, AVI, MOV, WMV, FLV, MKV, WebM, M4V

### 2. **AudioService** ✅
- Transcribes audio with Whisper API
- Supports timestamps
- Handles multiple languages
- Max file size: 25MB

### 3. **VisionService** ✅
- Analyzes images with GPT-4 Vision
- Describes visual content
- Extracts text from images (OCR)
- Supports: JPG, PNG, GIF, WebP

### 4. **DocumentService** ✅
- Extracts text from PDFs
- Processes Word documents
- Handles various formats
- Supports: PDF, DOC, DOCX, TXT

---

## 🔧 Setup Requirements

### 1. OpenAI API Key (Required)

```env
OPENAI_API_KEY=sk-your-api-key-here
```

The package uses OpenAI's APIs for:
- **Whisper** - Audio transcription
- **GPT-4 Vision** - Image analysis
- **Embeddings** - Vector generation

### 2. FFmpeg (Optional but Recommended)

For video processing, install FFmpeg:

**macOS:**
```bash
brew install ffmpeg
```

**Ubuntu/Debian:**
```bash
sudo apt-get install ffmpeg
```

**Verify installation:**
```bash
ffmpeg -version
```

### 3. PHP Extensions

Ensure these extensions are enabled:
```bash
php -m | grep fileinfo
php -m | grep curl
php -m | grep mbstring
```

---

## 🚀 How It Works

### Automatic Processing

Once configured, media processing happens automatically:

```php
use LaravelAIEngine\Traits\VectorizableWithMedia;

class Email extends Model
{
    use VectorizableWithMedia;
    
    // Auto-detects media fields
}

$email = Email::create([
    'subject' => 'Check this video',
    'attachment_url' => 'https://example.com/video.mp4',
]);

// Automatically:
// 1. Downloads video
// 2. Extracts audio → Transcribes with Whisper
// 3. Extracts frames → Analyzes with Vision
// 4. Combines text + media content
// 5. Creates embeddings
$vectorContent = $email->getVectorContent();
```

---

## 📊 Processing Flow

### Video Processing
```
1. Download video file
2. Check FFmpeg availability
3. Extract audio → Transcribe with Whisper
4. Extract key frames → Analyze with Vision
5. Combine: "Audio: [transcription] Visual: [descriptions]"
6. Return combined content
```

### Audio Processing
```
1. Download audio file
2. Check file size (< 25MB)
3. Transcribe with Whisper API
4. Return transcription
```

### Image Processing
```
1. Download image file
2. Analyze with GPT-4 Vision
3. Return description
```

### Document Processing
```
1. Download document
2. Extract text (PDF, DOC, etc.)
3. Return extracted text
```

---

## 💰 Costs

### OpenAI API Pricing
- **Whisper:** $0.006 per minute of audio
- **GPT-4 Vision:** ~$0.01 per image
- **Embeddings:** ~$0.0001 per 1K tokens

### Example Costs
```
30-minute video:
- Audio transcription: $0.18
- 5 frame analyses: $0.05
- Embeddings: $0.01
Total: ~$0.24

1-hour podcast:
- Audio transcription: $0.36
- Embeddings: $0.01
Total: ~$0.37
```

---

## 🧪 Testing Media Processing

### Test with Real File

```php
$email = Email::create([
    'subject' => 'Test Video',
    'video_path' => '/path/to/video.mp4',
]);

$vectorContent = $email->getVectorContent();

// Check if media was processed
if (str_contains($vectorContent, 'Audio Transcription')) {
    echo "✅ Video processed successfully!";
}
```

### Check Logs

```bash
tail -f storage/logs/ai-engine-$(date +%Y-%m-%d).log | grep -i media
```

You should see:
```
[INFO] Audio transcribed with Whisper
[INFO] Frame analysis completed
[INFO] Video processed successfully
```

---

## 🔍 Troubleshooting

### Issue: "FFmpeg not available"

**Solution:**
```bash
# Install FFmpeg
brew install ffmpeg  # macOS
sudo apt-get install ffmpeg  # Ubuntu

# Verify
ffmpeg -version
```

### Issue: "Audio file too large"

**Solution:**
Enable large media processing:
```env
AI_ENGINE_PROCESS_LARGE_MEDIA=true
AI_ENGINE_MEDIA_CHUNK_DURATION=60
```

### Issue: "OpenAI API error"

**Solution:**
Check your API key:
```env
OPENAI_API_KEY=sk-your-key-here
```

Verify it's valid:
```bash
php artisan tinker
>>> config('openai.api_key')
```

### Issue: "No media content in vector"

**Possible causes:**
1. File too large (check logs)
2. FFmpeg not installed (for video)
3. OpenAI API key missing
4. File format not supported

**Debug:**
```env
AI_ENGINE_DEBUG=true
```

Check logs for details.

---

## 📝 Configuration

### Full Configuration

```env
# OpenAI API
OPENAI_API_KEY=sk-your-key-here

# Media Processing
AI_ENGINE_PROCESS_LARGE_MEDIA=true
AI_ENGINE_MAX_MEDIA_FILE_SIZE=10485760  # 10MB
AI_ENGINE_MAX_MEDIA_CONTENT=50000  # 50KB
AI_ENGINE_MEDIA_CHUNK_DURATION=60  # 60 seconds

# Debug
AI_ENGINE_DEBUG=true
```

### Config File

```php
'vector' => [
    'media' => [
        'whisper_model' => 'whisper-1',
        'vision_model' => 'gpt-4-vision-preview',
        'max_image_size' => 20 * 1024 * 1024,  // 20MB
    ],
],
```

---

## ✅ Verification Checklist

Before using media processing:

- [ ] OpenAI API key configured
- [ ] FFmpeg installed (for video)
- [ ] PHP extensions enabled
- [ ] File size limits configured
- [ ] Debug logging enabled (initially)
- [ ] Test with sample file

---

## 🎯 Quick Start

### 1. Configure API Key
```env
OPENAI_API_KEY=sk-your-key-here
```

### 2. Install FFmpeg (Optional)
```bash
brew install ffmpeg
```

### 3. Test
```php
$email = Email::create([
    'subject' => 'Test',
    'attachment_url' => 'https://example.com/audio.mp3',
]);

$content = $email->getVectorContent();
// Should include transcription!
```

---

## 📚 Service Details

### VideoService

**Location:** `src/Services/Media/VideoService.php`

**Methods:**
- `processVideo($path)` - Process video file
- `getMetadata($path)` - Get video metadata
- `isSupported($extension)` - Check format support

**Features:**
- FFmpeg-based processing
- Audio extraction + transcription
- Key frame analysis
- Automatic cleanup

### AudioService

**Location:** `src/Services/Media/AudioService.php`

**Methods:**
- `transcribe($path)` - Transcribe audio
- `transcribeWithTimestamps($path)` - With timestamps

**Features:**
- Whisper API integration
- Multiple languages
- Timestamp support
- 25MB file limit

### VisionService

**Location:** `src/Services/Media/VisionService.php`

**Methods:**
- `analyzeImage($path)` - Analyze image
- `extractText($path)` - OCR

**Features:**
- GPT-4 Vision API
- Detailed descriptions
- Text extraction
- Multiple formats

### DocumentService

**Location:** `src/Services/Media/DocumentService.php`

**Methods:**
- `extractText($path)` - Extract text from document

**Features:**
- PDF support
- Word document support
- Text extraction
- Format detection

---

## 🚀 Production Ready!

All media processing services are:
- ✅ Fully implemented
- ✅ Production tested
- ✅ Error handled
- ✅ Logged comprehensively
- ✅ Cost tracked

**Just add your OpenAI API key and you're ready to go!** 🎯
