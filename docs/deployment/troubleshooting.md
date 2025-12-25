# Troubleshooting: "No RAG Results Found"

## 🎯 Your Situation

You're seeing this response:
```
"It seems like I couldn't find specific information about your top important emails at the moment..."
```

This means:
- ✅ RAG is working and searching
- ❌ No results are being found
- ✅ AI is now giving helpful guidance (new behavior!)

## 📊 Your Vector Status

```
| Model      | Total | Indexed | Pending |
+------------+-------+---------+---------+
| User       | 9     | 9       | 0       | ✅ Complete
| EmailCache | 10    | 9       | 1       | ⚠️  Partial
```

**Good**: You have 9 EmailCache items vectorized!  
**Issue**: Why isn't the search finding them?

---

## 🔍 Diagnostic Steps

### Step 1: Enable Debug Logging

```bash
# In your Bites project .env
AI_ENGINE_DEBUG=true
```

Then watch logs:
```bash
tail -f storage/logs/laravel.log | grep "ai-engine"
```

### Step 2: Check What the Logs Show

When you ask "what is my top important mails", you should see:

```json
{
  "message": "RAG search completed",
  "user_id": 1,
  "search_queries": ["important emails", "top emails"],
  "collections": ["App\\Models\\EmailCache"],
  "results_found": 0  // ← This tells you the problem!
}
```

Then:
```json
{
  "message": "Starting local vector search",
  "user_id": 1,
  "search_queries": ["important emails"],
  "collections": ["App\\Models\\EmailCache"],
  "max_results": 5,
  "threshold": 0.3
}
```

Then for each search:
```json
{
  "message": "Vector search results",
  "collection": "App\\Models\\EmailCache",
  "query": "important emails",
  "user_id": 1,
  "results_count": 0,  // ← The actual problem
  "threshold": 0.3
}
```

---

## 🐛 Common Issues & Solutions

### Issue 1: Wrong Collection Name

**Problem**: You're searching `Email::class` but data is in `EmailCache::class`

**Check**:
```php
php artisan tinker

// What model are you using?
class_exists(\App\Models\Email::class);
class_exists(\App\Models\EmailCache::class);

// Which one has data?
\App\Models\Email::count();
\App\Models\EmailCache::count();
```

**Solution**: Use the correct model in your controller:
```php
use App\Models\EmailCache; // ← Not Email!

$response = $chat->processMessage(
    message: $request->message,
    sessionId: $sessionId,
    useIntelligentRAG: true,
    ragCollections: [EmailCache::class], // ← Important!
    userId: $request->user()->id
);
```

---

### Issue 2: User ID Mismatch

**Problem**: Emails belong to user_id 5, but you're logged in as user_id 1

**Check**:
```php
php artisan tinker

// Your current user ID
$userId = auth()->id(); // or the ID you're using
echo "Your user ID: {$userId}\n";

// Check if EmailCache has data for this user
$count = \App\Models\EmailCache::where('user_id', $userId)->count();
echo "Emails for user {$userId}: {$count}\n";

// If 0, check what user_ids exist
$userIds = \App\Models\EmailCache::pluck('user_id')->unique();
echo "User IDs in EmailCache: " . $userIds->implode(', ') . "\n";
```

**Solution**: 
- Either use the correct user ID
- Or vectorize emails for your current user

---

### Issue 3: Similarity Threshold Too High

**Problem**: Your emails score below 0.3 (30% similarity)

**Check**: Look at the logs for actual scores:
```json
{
  "message": "Vector search results",
  "results_count": 0,
  "threshold": 0.3  // ← Maybe too high
}
```

**Solution**: Lower the threshold in config:
```php
// config/ai-engine.php
'rag' => [
    'min_relevance_score' => 0.1,  // Lower from 0.3
    'fallback_threshold' => 0.05,  // Even lower for fallback
],
```

Or in your controller:
```php
$response = $chat->processMessage(
    message: $request->message,
    sessionId: $sessionId,
    useIntelligentRAG: true,
    ragCollections: [EmailCache::class],
    userId: $request->user()->id,
    options: [
        'min_score' => 0.1,  // Lower threshold
    ]
);
```

---

### Issue 4: Search Query Doesn't Match Content

**Problem**: You're searching "important emails" but emails don't contain those words

**Check**: What's actually in your emails?
```php
php artisan tinker

$email = \App\Models\EmailCache::first();

// Check what gets vectorized
echo $email->getVectorContent();

// Check metadata
print_r($email->getVectorMetadata());
```

**Solution**: Try different search terms:
- Instead of "important emails" → try "emails"
- Instead of "top mails" → try "messages"
- Use actual subject keywords from your emails

---

### Issue 5: EmailCache Model Not Using Vectorizable Trait

**Problem**: Model doesn't have vectorization methods

**Check**:
```php
php artisan tinker

$email = \App\Models\EmailCache::first();

// Does it have vectorize method?
method_exists($email, 'vectorize'); // Should be true

// Does it have getVectorContent?
method_exists($email, 'getVectorContent'); // Should be true

// Check traits
$traits = class_uses_recursive(\App\Models\EmailCache::class);
print_r($traits);
// Should include: LaravelAIEngine\Traits\Vectorizable
```

**Solution**: Add trait to your model:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

class EmailCache extends Model
{
    use Vectorizable;  // ← Add this
    
    // Define what to vectorize
    public function getVectorContent(): string
    {
        return implode("\n", [
            "Subject: {$this->subject}",
            "From: {$this->from_address}",
            "Body: {$this->body}",
        ]);
    }
    
    // Define metadata for filtering
    public function getVectorMetadata(): array
    {
        return [
            'user_id' => $this->user_id,
            'from_address' => $this->from_address,
            'subject' => $this->subject,
        ];
    }
}
```

---

### Issue 6: Emails Not Actually Vectorized

**Problem**: Status shows "Indexed: 9" but they're not in vector DB

**Check**:
```bash
# Check Qdrant directly
php artisan tinker

use LaravelAIEngine\Services\Vector\VectorSearchService;

$vectorSearch = app(VectorSearchService::class);

// Try searching with very low threshold
$results = $vectorSearch->search(
    modelClass: \App\Models\EmailCache::class,
    query: 'email',
    limit: 10,
    threshold: 0.0,  // Accept anything!
    userId: 1
);

echo "Results found: " . $results->count() . "\n";

if ($results->count() > 0) {
    echo "First result:\n";
    print_r($results->first());
}
```

**Solution**: Re-vectorize:
```bash
php artisan ai-engine:vector-index "App\Models\EmailCache" --force
```

---

## 🎯 Quick Test Script

Create: `test-email-rag.php`

```php
<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use LaravelAIEngine\Services\ChatService;
use App\Models\EmailCache;

echo "=== Email RAG Diagnostic ===\n\n";

// 1. Check model exists
echo "✓ EmailCache model exists: " . (class_exists(EmailCache::class) ? 'Yes' : 'No') . "\n";

// 2. Check data
$totalEmails = EmailCache::count();
echo "✓ Total EmailCache records: {$totalEmails}\n";

// 3. Check for your user
$userId = 1; // Change this to your user ID
$userEmails = EmailCache::where('user_id', $userId)->count();
echo "✓ Emails for user {$userId}: {$userEmails}\n";

if ($userEmails === 0) {
    echo "\n❌ No emails for user {$userId}!\n";
    echo "Available user IDs: " . EmailCache::pluck('user_id')->unique()->implode(', ') . "\n";
    exit;
}

// 4. Check vectorization
$email = EmailCache::where('user_id', $userId)->first();
echo "✓ Has vectorize method: " . (method_exists($email, 'vectorize') ? 'Yes' : 'No') . "\n";
echo "✓ Has getVectorContent: " . (method_exists($email, 'getVectorContent') ? 'Yes' : 'No') . "\n";

// 5. Show what gets vectorized
echo "\n--- Sample Email Content ---\n";
if (method_exists($email, 'getVectorContent')) {
    echo substr($email->getVectorContent(), 0, 200) . "...\n";
}

// 6. Test vector search directly
echo "\n--- Testing Vector Search ---\n";
$vectorSearch = app(\LaravelAIEngine\Services\Vector\VectorSearchService::class);

$results = $vectorSearch->search(
    modelClass: EmailCache::class,
    query: 'email',
    limit: 5,
    threshold: 0.1,
    userId: $userId
);

echo "Results found: " . $results->count() . "\n";

if ($results->count() > 0) {
    echo "\nFirst result:\n";
    $first = $results->first();
    echo "  Subject: " . ($first->subject ?? 'N/A') . "\n";
    echo "  Score: " . ($first->vector_score ?? 'N/A') . "\n";
}

// 7. Test full RAG
echo "\n--- Testing Full RAG ---\n";
$chat = app(ChatService::class);

$response = $chat->processMessage(
    message: 'Show me my emails',
    sessionId: 'test-' . time(),
    useIntelligentRAG: true,
    ragCollections: [EmailCache::class],
    userId: $userId
);

echo "\nAI Response:\n";
echo $response->content . "\n";

$metadata = $response->getMetadata();
echo "\nSources found: " . count($metadata['sources'] ?? []) . "\n";

echo "\n=== Done ===\n";
```

Run it:
```bash
php test-email-rag.php
```

---

## 📝 What to Share for Help

If you're still stuck, share these details:

1. **Log output** (with AI_ENGINE_DEBUG=true):
```bash
tail -100 storage/logs/laravel.log | grep "ai-engine"
```

2. **Test script output**:
```bash
php test-email-rag.php
```

3. **Model check**:
```php
php artisan tinker
$email = \App\Models\EmailCache::first();
echo "Has Vectorizable: " . in_array(\LaravelAIEngine\Traits\Vectorizable::class, class_uses_recursive($email)) . "\n";
echo "User ID: " . $email->user_id . "\n";
echo "Content: " . $email->getVectorContent() . "\n";
```

---

## ✅ Expected Behavior

**When working correctly**, you should see:

```
User: "what is my top important mails"

Logs:
  ✓ RAG search completed: results_found: 3
  ✓ Vector search results: results_count: 3

AI Response:
  "Here are your most important emails, Mohamed:
   
   1. **Project Deadline Reminder** from Sarah
      - Sent: 2024-12-01
      - Priority: High
      [Source 0]
   
   2. **Invoice #12345** from Accounting
      - Sent: 2024-11-30
      [Source 1]
   
   3. **Meeting Notes** from John
      - Sent: 2024-11-29
      [Source 2]"
```

---

## 🎯 Most Likely Issue

Based on your status showing 9/10 indexed, the most likely issues are:

1. **User ID mismatch** - Emails belong to different user
2. **Threshold too high** - Emails score below 0.3
3. **Wrong collection name** - Using Email instead of EmailCache

Run the test script above to find out! 🚀
