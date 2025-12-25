# Media Field Auto-Detection

The `HasMediaEmbeddings` trait now automatically detects media fields, supports arrays of URLs, and processes media from relationships!

## Features

✅ **Auto-Detection** - Automatically finds media fields  
✅ **Array Support** - Handles arrays of URLs/paths  
✅ **Relationship Support** - Processes media from related models  
✅ **URL Detection** - Automatically downloads from URLs  
✅ **Zero Configuration** - Works out of the box  

---

## 1. Auto-Detection

### No Configuration Needed

```php
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\HasMediaEmbeddings;

class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    // No need to define $mediaFields!
    // Automatically detects: image_url, photo_path, document_file, etc.
}
```

### What Gets Detected

**Column Patterns:**
- `image_url`, `image_path`, `photo_url`, `avatar_path`
- `audio_url`, `audio_path`, `sound_file`, `podcast_url`
- `video_url`, `video_path`, `movie_file`
- `document_url`, `document_path`, `file_path`, `pdf_url`

**Relationship Methods:**
- `attachments()`, `images()`, `photos()`, `files()`, `documents()`, `media()`

---

## 2. Array Support

### Single URL

```php
$post = Post::create([
    'title' => 'My Post',
    'image_url' => 'https://example.com/image.jpg',  // Single URL
]);
```

### Array of URLs

```php
$post = Post::create([
    'title' => 'Gallery Post',
    'image_url' => [  // Array of URLs!
        'https://example.com/image1.jpg',
        'https://example.com/image2.jpg',
        'https://example.com/image3.jpg',
    ],
]);

// All images are automatically processed!
$vectorContent = $post->getVectorContent();
```

### JSON Column

```php
// Migration
Schema::create('posts', function (Blueprint $table) {
    $table->json('images');  // JSON column
});

// Usage
$post = Post::create([
    'title' => 'Multi-Image Post',
    'images' => [
        'https://cdn.example.com/photo1.jpg',
        'https://cdn.example.com/photo2.jpg',
    ],
]);

// Cast to array in model
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    protected $casts = [
        'images' => 'array',
    ];
}
```

---

## 3. Relationship Support

### HasMany Relationship

```php
class Email extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
    
    // No $mediaFields needed - auto-detects attachments()!
}

class Attachment extends Model
{
    // Must have one of: url, path, file_path, image_url, etc.
    protected $fillable = ['url', 'type'];
}

// Usage
$email = Email::create(['subject' => 'Test']);
$email->attachments()->create(['url' => 'https://example.com/file.pdf']);

// Automatically processes all attachments!
$vectorContent = $email->getVectorContent();
```

### HasOne Relationship

```php
class User extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function avatar()
    {
        return $this->hasOne(Avatar::class);
    }
}

class Avatar extends Model
{
    protected $fillable = ['url'];
}

$user = User::create(['name' => 'John']);
$user->avatar()->create(['url' => 'https://example.com/avatar.jpg']);

// Automatically processes avatar!
$vectorContent = $user->getVectorContent();
```

### MorphMany Relationship

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}

class Image extends Model
{
    protected $fillable = ['url', 'imageable_type', 'imageable_id'];
}

$post = Post::create(['title' => 'Gallery']);
$post->images()->create(['url' => 'https://example.com/photo1.jpg']);
$post->images()->create(['url' => 'https://example.com/photo2.jpg']);

// Processes all related images!
$vectorContent = $post->getVectorContent();
```

---

## 4. Mixed Scenarios

### Arrays + Relationships

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    protected $casts = [
        'gallery' => 'array',  // Array of URLs
    ];
    
    public function attachments()  // Relationship
    {
        return $this->hasMany(Attachment::class);
    }
}

$post = Post::create([
    'title' => 'Mixed Media',
    'gallery' => [  // Array
        'https://example.com/img1.jpg',
        'https://example.com/img2.jpg',
    ],
]);

$post->attachments()->create([  // Relationship
    'url' => 'https://example.com/document.pdf',
]);

// Processes both gallery array AND attachments!
$vectorContent = $post->getVectorContent();
```

### URLs + Local Paths

```php
$post = Post::create([
    'title' => 'Mixed Sources',
    'images' => [
        'https://example.com/remote.jpg',  // URL
        '/storage/images/local.jpg',       // Local path
    ],
]);

// Handles both URLs and local paths!
```

---

## 5. Auto-Detection Examples

### Example 1: Blog Post

```php
// Migration
Schema::create('posts', function (Blueprint $table) {
    $table->string('title');
    $table->text('content');
    $table->string('featured_image_url')->nullable();
    $table->string('thumbnail_path')->nullable();
});

// Model - NO configuration needed!
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
}

// Auto-detects:
// - featured_image_url (image)
// - thumbnail_path (image)
```

### Example 2: Email with Attachments

```php
// Email model
class Email extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}

// Attachment model
class Attachment extends Model
{
    protected $fillable = ['url', 'filename', 'type'];
}

// Auto-detects attachments() relationship!
```

### Example 3: Product with Gallery

```php
// Migration
Schema::create('products', function (Blueprint $table) {
    $table->string('name');
    $table->json('gallery_images');  // JSON array
    $table->string('main_image_url');
});

// Model
class Product extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    protected $casts = [
        'gallery_images' => 'array',
    ];
}

// Auto-detects:
// - gallery_images (array of images)
// - main_image_url (single image)
```

---

## 6. Explicit Configuration (Optional)

You can still explicitly define media fields if needed:

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // Explicit configuration (optional)
        $this->mediaFields = [
            'image' => 'custom_image_field',
            'document' => 'attachments',  // Relationship
        ];
    }
}
```

---

## 7. Logging

### Debug Logs

```env
AI_ENGINE_DEBUG=true
```

```json
{
  "message": "Auto-detected media fields",
  "model": "App\\Models\\Post",
  "detected_fields": {
    "image": "featured_image_url",
    "document": "attachments"
  }
}
```

---

## 8. Use Cases

### Email System

```php
class Email extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}

// Automatically processes all email attachments
// Search works: "email with PDF attachment about invoice"
```

### Social Media

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    protected $casts = [
        'media_urls' => 'array',  // Multiple images/videos
    ];
}

// Automatically processes all media
// Search works: "post with sunset photo"
```

### Document Management

```php
class Document extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function files()
    {
        return $this->hasMany(File::class);
    }
}

// Automatically processes all files
// Search works: "document containing contract terms"
```

### E-commerce

```php
class Product extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    protected $casts = [
        'images' => 'array',  // Product gallery
    ];
    
    public function documents()
    {
        return $this->hasMany(ProductDocument::class);
    }
}

// Processes images + documents
// Search works: "product with user manual"
```

---

## 9. Performance

### Lazy Loading

Relationships are loaded when needed:

```php
// Only loads attachments if they exist
$email->getVectorContent();
```

### Caching

Consider eager loading for better performance:

```php
$emails = Email::with('attachments')->get();

foreach ($emails as $email) {
    $email->getVectorContent();  // No N+1 queries!
}
```

---

## 10. Error Handling

### Missing Relationships

```php
// If relationship doesn't exist, gracefully skips
[warning] Failed to process relation media
  relation: attachments
  error: Call to undefined method
```

### Invalid URLs

```php
// If URL download fails, continues with other media
[warning] Failed to download URL media
  url: https://invalid.com/file.jpg
```

### Corrupted Files

```php
// If file processing fails, logs and continues
[error] Error processing URL media
  url: https://example.com/corrupted.jpg
  error: Invalid image format
```

---

## Benefits

✅ **Zero Configuration** - Works automatically  
✅ **Flexible** - Supports arrays, URLs, relationships  
✅ **Scalable** - Handles multiple media per model  
✅ **Robust** - Graceful error handling  
✅ **Smart** - Auto-detects common patterns  
✅ **Comprehensive** - Logs everything  

---

## Migration Guide

### Before (Manual)

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        $this->mediaFields = [
            'image' => 'image_url',
        ];
    }
}
```

### After (Auto)

```php
class Post extends Model
{
    use Vectorizable, HasMediaEmbeddings;
    
    // That's it! Auto-detects image_url
}
```

---

## Status

✅ **Production Ready**  
✅ **Fully Tested**  
✅ **Comprehensive Logging**  
✅ **Error Handling**  

**Use with confidence!** 🚀
