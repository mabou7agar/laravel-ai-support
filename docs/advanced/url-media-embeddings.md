# URL Media Embeddings

The `HasMediaEmbeddings` trait now supports automatic detection and processing of URLs in media fields.

## Features

✅ **Automatic URL Detection** - Detects if field contains URL  
✅ **Auto Download** - Downloads media from URL to temp file  
✅ **Process & Cleanup** - Processes media and cleans up temp files  
✅ **Multiple Types** - Supports images, audio, video, documents  
✅ **Error Handling** - Graceful fallback on failures  
✅ **Logging** - Comprehensive debug logs  

---

## Usage

### Basic Example

```php
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\HasMediaEmbeddings;

class Email extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // Define text fields
        $this->vectorizable = ['subject', 'body'];
        
        // Define media fields (supports URLs!)
        $this->mediaFields = [
            'image' => 'attachment_url',
            'document' => 'pdf_url',
        ];
    }
}
```

### Create with URLs

```php
$email = Email::create([
    'subject' => 'Important Document',
    'body' => 'Please review the attached document.',
    'attachment_url' => 'https://example.com/image.jpg',
    'pdf_url' => 'https://example.com/document.pdf',
]);

// Get vector content (automatically downloads and processes URLs)
$vectorContent = $email->getVectorContent();
```

---

## How It Works

### 1. URL Detection

```php
// Automatically detects URLs
'https://example.com/image.jpg'  // ✅ URL - will download
'/storage/images/photo.jpg'      // ❌ Local path - normal processing
```

### 2. Download Process

```php
// Downloads to temp file
$tempFile = tempnam(sys_get_temp_dir(), 'media_') . '.jpg';
file_put_contents($tempFile, file_get_contents($url));
```

### 3. Processing

```php
// Processes based on type
match($type) {
    'image' => VisionService::analyzeImage($tempFile),
    'audio' => AudioService::transcribe($tempFile),
    'video' => VideoService::processVideo($tempFile),
    'document' => DocumentService::extractText($tempFile),
}
```

### 4. Cleanup

```php
// Automatically removes temp file
@unlink($tempFile);
```

---

## Supported Media Types

### Images
```php
$this->mediaFields = [
    'image' => 'image_url',
];

// Supports:
// - https://example.com/photo.jpg
// - https://cdn.example.com/images/banner.png
// - https://s3.amazonaws.com/bucket/image.webp
```

### Audio
```php
$this->mediaFields = [
    'audio' => 'audio_url',
];

// Supports:
// - https://example.com/podcast.mp3
// - https://cdn.example.com/audio/recording.wav
// - https://storage.googleapis.com/bucket/audio.m4a
```

### Video
```php
$this->mediaFields = [
    'video' => 'video_url',
];

// Supports:
// - https://example.com/video.mp4
// - https://cdn.example.com/videos/tutorial.mov
// - https://vimeo.com/video/123456789
```

### Documents
```php
$this->mediaFields = [
    'document' => 'document_url',
];

// Supports:
// - https://example.com/report.pdf
// - https://docs.google.com/document/export?format=pdf
// - https://cdn.example.com/files/contract.docx
```

---

## Multiple URLs

```php
class EmailAttachment extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['subject', 'body'];
        
        // Multiple media fields with URLs
        $this->mediaFields = [
            'image' => 'image_url',
            'document' => 'pdf_url',
            'audio' => 'voice_note_url',
        ];
    }
}

$email = EmailAttachment::create([
    'subject' => 'Meeting Notes',
    'body' => 'See attachments',
    'image_url' => 'https://example.com/screenshot.png',
    'pdf_url' => 'https://example.com/notes.pdf',
    'voice_note_url' => 'https://example.com/recording.mp3',
]);

// All URLs are downloaded and processed automatically
$vectorContent = $email->getVectorContent();
```

---

## Mixed Local and URL

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['title', 'content'];
        
        $this->mediaFields = [
            'image' => 'featured_image',  // Can be URL or local path
            'document' => 'attachment',   // Can be URL or local path
        ];
    }
}

// Works with URLs
$post1 = Post::create([
    'title' => 'Post 1',
    'featured_image' => 'https://example.com/image.jpg',  // ✅ URL
]);

// Works with local paths
$post2 = Post::create([
    'title' => 'Post 2',
    'featured_image' => '/storage/images/photo.jpg',  // ✅ Local
]);

// Both work seamlessly!
```

---

## Configuration

### Timeout

```php
// Default: 30 seconds
// Configured in downloadUrlToTemp() method

'http' => [
    'timeout' => 30,
    'user_agent' => 'Laravel-AI-Engine/1.0',
]
```

### Debug Logging

```env
AI_ENGINE_DEBUG=true
```

```php
// Logs URL processing
[debug] Processed URL media
  url: https://example.com/image.jpg
  type: image
  content_length: 1234
```

---

## Error Handling

### Failed Downloads

```php
// Logs warning and continues
[warning] Failed to download URL media
  url: https://invalid-url.com/image.jpg
  type: image
```

### Processing Errors

```php
// Logs error and returns null
[error] Error processing URL media
  url: https://example.com/corrupted.jpg
  type: image
  error: Invalid image format
```

### Graceful Degradation

```php
// If URL media fails, text content is still vectorized
$email = Email::create([
    'subject' => 'Important',
    'body' => 'See attachment',
    'attachment_url' => 'https://broken-link.com/file.pdf',  // ❌ Fails
]);

// Still gets vector content from subject + body
$vectorContent = $email->getVectorContent();  // ✅ Works
```

---

## Use Cases

### 1. Email Attachments

```php
class EmailCache extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['subject', 'from', 'to', 'body'];
        
        $this->mediaFields = [
            'image' => 'attachment_url',  // Email attachment URL
        ];
    }
}
```

### 2. Social Media Posts

```php
class SocialPost extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['caption', 'description'];
        
        $this->mediaFields = [
            'image' => 'media_url',  // Instagram/Twitter image URL
            'video' => 'video_url',  // TikTok/YouTube video URL
        ];
    }
}
```

### 3. Document Management

```php
class Document extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['title', 'description'];
        
        $this->mediaFields = [
            'document' => 'file_url',  // Google Drive/Dropbox URL
        ];
    }
}
```

### 4. Support Tickets

```php
class SupportTicket extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['subject', 'description'];
        
        $this->mediaFields = [
            'image' => 'screenshot_url',  // User uploaded screenshot
            'document' => 'log_file_url', // Error log file
        ];
    }
}
```

---

## Benefits

✅ **No Manual Downloads** - Automatic URL detection and download  
✅ **Seamless Integration** - Works with existing code  
✅ **Mixed Sources** - Supports both URLs and local files  
✅ **Error Resilient** - Graceful handling of failures  
✅ **Auto Cleanup** - Temp files automatically removed  
✅ **Comprehensive Logs** - Full visibility into processing  

---

## Example: Email with URL Attachment

```php
// Model setup
class Email extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->vectorizable = ['subject', 'body'];
        $this->mediaFields = ['image' => 'attachment_url'];
    }
}

// Create email with URL attachment
$email = Email::create([
    'subject' => 'Product Screenshot',
    'body' => 'Here is the screenshot you requested.',
    'attachment_url' => 'https://cdn.example.com/screenshots/product.png',
]);

// Get vector content
// 1. Downloads image from URL
// 2. Analyzes image with Vision API
// 3. Combines: subject + body + image analysis
// 4. Cleans up temp file
$vectorContent = $email->getVectorContent();

// Search works perfectly!
// Can find emails by:
// - Subject keywords
// - Body content
// - Image content (e.g., "screenshot showing login button")
```

---

## Status

✅ **Production Ready**  
✅ **Fully Tested**  
✅ **Comprehensive Logging**  
✅ **Error Handling**  

**Use with confidence!** 🚀
