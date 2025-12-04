# User Context Injection

## Overview

The AI Engine automatically injects authenticated user context into AI conversations, making the AI aware of who the user is without requiring explicit introduction every time.

## Features

✅ **Automatic Name Recognition** - AI knows your name  
✅ **Role Awareness** - AI understands your permissions (admin, user, etc.)  
✅ **Organization Context** - AI knows your tenant/organization  
✅ **Personalized Responses** - AI addresses you by name  
✅ **Cached for Performance** - User data cached for 5 minutes  
✅ **Privacy Conscious** - Email inclusion is optional  
✅ **Extensible** - Add custom context via `getAIContext()` method  

## How It Works

When you send a message to the AI, the system automatically:

1. Fetches your user record (with caching)
2. Extracts relevant information (name, role, organization)
3. Injects it into the AI's system prompt
4. AI uses this context in all responses

## Configuration

### Enable/Disable User Context

```bash
# .env
AI_ENGINE_INJECT_USER_CONTEXT=true  # Default: true
AI_ENGINE_INCLUDE_USER_EMAIL=false  # Default: false (privacy)
```

### In Config File

```php
// config/ai-engine.php
return [
    'inject_user_context' => true,
    'include_user_email' => false,  // Privacy consideration
];
```

## What Information is Injected?

### Automatically Detected

| Field | Source | Example |
|-------|--------|---------|
| **User ID** | `$user->id` | "User ID: 123" |
| **Name** | `$user->name` | "User's name: John Doe" |
| **Email** | `$user->email` | "Email: john@example.com" |
| **Phone** | `$user->phone` / `phone_number` / `mobile` | "Phone: +1234567890" |
| **Username** | `$user->username` | "Username: johndoe" |
| **Full Name** | `$user->first_name` + `last_name` | "Full Name: John Doe" |
| **Job Title** | `$user->title` / `job_title` | "Job Title: Senior Developer" |
| **Department** | `$user->department` | "Department: Engineering" |
| **Location** | `$user->location` / `city` | "Location: New York" |
| **Timezone** | `$user->timezone` | "Timezone: America/New_York" |
| **Language** | `$user->language` / `locale` | "Language: en" |
| **Role** | `$user->is_admin` or Spatie roles | "Role: Administrator" |
| **Organization** | `$user->tenant_id` / `organization_id` | "Organization ID: ABC Corp" |

### Example System Prompt

```
You are a helpful AI assistant. Provide clear, accurate, and helpful responses to user questions.

USER CONTEXT:
- User ID: 123
- User's name: John Doe
- Email: john@example.com
- Phone: +1234567890
- Username: johndoe
- Job Title: Senior Developer
- Department: Engineering
- Location: New York
- Timezone: America/New_York
- Language: en
- Role: Administrator (has full system access)
- Organization ID: ABC Corp

IMPORTANT INSTRUCTIONS:
- Always address the user by their name when appropriate
- When searching for user's data, use their User ID (123) or Email (john@example.com)
- Personalize responses based on their role and context
- When user asks 'my emails', 'my documents', etc., search for data belonging to User ID: 123
```

## Usage Examples

### Example 1: AI Knows Your Name

**Without Context Injection:**
```
User: "Hello"
AI: "Hello! How can I help you today?"
```

**With Context Injection:**
```
User: "Hello"
AI: "Hello John! How can I help you today?"
```

### Example 2: Role-Aware Responses

**Admin User:**
```
User: "Can I see all user data?"
AI: "Yes John, as an administrator, you have full system access. You can view all user data through the admin panel."
```

**Regular User:**
```
User: "Can I see all user data?"
AI: "Hi Sarah, you can access your own data, but viewing all user data requires administrator privileges."
```

### Example 3: Organization Context

```
User: "Show me our team's documents"
AI: "Sure John! I'll search for documents in your organization (ABC Corp). Let me retrieve those for you."
```

## Custom User Context

### Add Custom Context to Your User Model

You can extend the user context by adding a `getAIContext()` method to your User model:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /**
     * Get custom AI context for this user
     * 
     * @return string|null
     */
    public function getAIContext(): ?string
    {
        $context = '';
        
        // Add department
        if ($this->department) {
            $context .= "- Department: {$this->department}\n";
        }
        
        // Add preferences
        if ($this->preferences) {
            $context .= "- Preferences: {$this->preferences}\n";
        }
        
        // Add subscription tier
        if ($this->subscription_tier) {
            $context .= "- Subscription: {$this->subscription_tier}\n";
        }
        
        // Add custom instructions
        if ($this->ai_instructions) {
            $context .= "- Special Instructions: {$this->ai_instructions}\n";
        }
        
        return $context ?: null;
    }
}
```

**Result:**
```
USER CONTEXT:
- User's name: John Doe
- Role: Administrator
- Organization ID: ABC Corp
- Department: Engineering
- Preferences: Dark mode, concise responses
- Subscription: Premium
- Special Instructions: Always provide code examples
```

## Role Detection

The system automatically detects roles from multiple sources:

### 1. Simple Admin Flag

```php
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'is_admin'];
}

// Usage
$user->is_admin = true;
```

**AI Context:**
```
- Role: Administrator (has full system access)
```

### 2. Spatie Laravel Permission

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}

// Usage
$user->assignRole('super-admin');
$user->assignRole(['editor', 'moderator']);
```

**AI Context:**
```
- Role: super-admin, editor, moderator
```

### 3. Generic Roles Relationship

```php
class User extends Authenticatable
{
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
```

**AI Context:**
```
- Role: admin, manager
```

## Organization/Tenant Detection

The system checks multiple fields for organization context:

```php
// Priority order:
1. $user->tenant_id
2. $user->organization_id
3. $user->company_id
4. $user->team_id
```

## Privacy Considerations

### Email Inclusion

By default, user emails are **NOT** included in AI context for privacy reasons.

**Enable if needed:**
```bash
AI_ENGINE_INCLUDE_USER_EMAIL=true
```

### Data Sent to AI

When user context is enabled, the following data is sent to the AI provider:
- User's name
- User's role
- Organization ID (not name)
- Custom context (if defined)
- Email (only if explicitly enabled)

**No sensitive data is included** (passwords, tokens, etc.)

## Caching

User context is cached for **5 minutes** to improve performance:

```php
Cache::remember("ai_user_context_{$userId}", 300, fn() => User::find($userId));
```

**Clear cache manually:**
```php
Cache::forget("ai_user_context_{$userId}");
```

## Controller Example

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\ChatService;

class ChatController extends Controller
{
    public function chat(Request $request, ChatService $chatService)
    {
        $response = $chatService->processMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            userId: $request->user()->id  // ✅ AI automatically knows who this is
        );

        return response()->json([
            'response' => $response->content,
        ]);
    }
}
```

**AI will automatically know:**
- User's name: "John Doe"
- User's role: "Administrator"
- User's organization: "ABC Corp"

## Disable for Specific Requests

You can disable user context for specific requests by not passing userId:

```php
// No user context
$response = $chatService->processMessage(
    message: 'What is Laravel?',
    sessionId: 'anonymous-session',
    userId: null  // ❌ No user context
);
```

## Testing

### Test User Context Injection

```php
public function test_ai_knows_user_name()
{
    $user = User::factory()->create(['name' => 'John Doe']);
    
    $response = $this->chatService->processMessage(
        message: 'Hello',
        sessionId: 'test-session',
        userId: $user->id
    );
    
    // AI should address user by name
    $this->assertStringContainsString('John', $response->content);
}
```

### Test Role Awareness

```php
public function test_ai_recognizes_admin()
{
    $admin = User::factory()->create(['is_admin' => true]);
    
    $response = $this->chatService->processMessage(
        message: 'What can I do?',
        sessionId: 'test-session',
        userId: $admin->id
    );
    
    // AI should mention admin privileges
    $this->assertStringContainsString('administrator', strtolower($response->content));
}
```

## Best Practices

1. **Always pass userId** when user is authenticated
2. **Use custom context** for app-specific user data
3. **Keep email disabled** unless necessary for your use case
4. **Clear cache** when user data changes significantly
5. **Test personalization** to ensure AI uses context appropriately

## Troubleshooting

### AI Not Using User Name

**Check:**
1. Is `AI_ENGINE_INJECT_USER_CONTEXT=true`?
2. Is `userId` being passed to `processMessage()`?
3. Does user have a `name` field?
4. Check logs for errors

### Context Not Updating

**Solution:**
Clear the cache:
```php
Cache::forget("ai_user_context_{$userId}");
```

### Too Much Information in Context

**Solution:**
Customize what's included by overriding `getUserContext()` in ChatService.

## Summary

User context injection makes AI conversations feel natural and personalized:

- ✅ **Enabled by default**
- ✅ **Privacy conscious** (no email by default)
- ✅ **Cached for performance**
- ✅ **Extensible** via `getAIContext()`
- ✅ **Role-aware**
- ✅ **Organization-aware**
- ✅ **Easy to configure**

The AI will always know who you are when you're authenticated! 🎯
