# Node Authentication Header Changes

## Overview

Node authentication has been updated to use a dedicated `X-Node-Token` header instead of the `Authorization` header. This prevents conflicts with standard user authentication that typically uses the `Authorization: Bearer` header.

## Changes Made

### 1. NodeAuthMiddleware Updates

**File:** `packages/laravel-ai-engine/src/Http/Middleware/NodeAuthMiddleware.php`

The middleware now checks for authentication tokens in the following order:

1. **`X-Node-Token` header** (preferred) - New dedicated header for node authentication
2. **`Authorization: Bearer` header** (fallback) - For backward compatibility
3. **`X-API-Key` header** (alternative) - For API key authentication
4. **`api_key` query parameter** (not recommended for production)

```php
protected function extractToken(Request $request): ?string
{
    // Try X-Node-Token header first (preferred for node authentication)
    $nodeToken = $request->header('X-Node-Token');
    if ($nodeToken) {
        return $nodeToken;
    }
    
    // Try Authorization header as fallback (Bearer token) for backward compatibility
    $authHeader = $request->header('Authorization');
    if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
        return substr($authHeader, 7);
    }
    
    // Try X-API-Key header
    $apiKey = $request->header('X-API-Key');
    if ($apiKey) {
        return $apiKey;
    }
    
    // Try query parameter (not recommended for production)
    return $request->query('api_key');
}
```

### 2. NodeHttpClient Updates

**File:** `packages/laravel-ai-engine/src/Services/Node/NodeHttpClient.php`

#### Updated Methods:

1. **`makeAuthenticated()`** - Now sends `X-Node-Token` instead of `Authorization: Bearer`
   - Added `$forwardHeaders` parameter to support forwarding request headers
   
2. **`getSearchHeaders()`** - Returns `X-Node-Token` in headers array
   - Added `$forwardHeaders` parameter to support forwarding request headers

#### New Method:

**`extractForwardableHeaders()`** - Extracts headers from current request to forward to nodes

This method intelligently forwards relevant headers while avoiding conflicts:

```php
public static function extractForwardableHeaders(?Request $request = null): array
```

**Forwarded Headers:**
- `X-Request-Id` - Request tracking
- `X-Trace-Id` - Distributed tracing
- `X-Correlation-Id` - Request correlation
- `X-User-Id` - User identification
- `X-Tenant-Id` - Multi-tenancy support
- `X-Workspace-Id` - Workspace context
- `Accept-Language` - Localization
- `User-Agent` - Client information
- `Referer` - Request origin

**Special Handling for Authorization:**
- User `Authorization: Bearer` tokens are forwarded as `X-User-Authorization` to avoid conflict with node authentication
- Non-Bearer authorization headers are forwarded as-is

## Usage Examples

### Basic Node Authentication

```php
use LaravelAIEngine\Services\Node\NodeHttpClient;

// Create authenticated client (uses X-Node-Token)
$client = NodeHttpClient::makeAuthenticated($node);

// Make request
$response = $client->post($node->url . '/api/search', $data);
```

### Automatic Request Header Forwarding

The `makeForSearch()` and `makeForAction()` methods now **automatically** extract and forward request headers:

```php
use LaravelAIEngine\Services\Node\NodeHttpClient;

// Automatically forwards user context headers
$client = NodeHttpClient::makeForSearch($node);
$response = $client->post($node->url . '/api/search', $data);

// Or for actions
$client = NodeHttpClient::makeForAction($node);
$response = $client->post($node->url . '/api/actions', $data);

// Disable automatic forwarding if needed
$client = NodeHttpClient::makeForSearch($node, null, false);
```

### Manual Request Header Forwarding

```php
use LaravelAIEngine\Services\Node\NodeHttpClient;

// Extract forwardable headers from current request
$forwardHeaders = NodeHttpClient::extractForwardableHeaders(request());

// Create authenticated client with forwarded headers
$client = NodeHttpClient::makeAuthenticated($node, false, 300, $forwardHeaders);

// Make request - will include both node auth and user context
$response = $client->post($node->url . '/api/search', $data);
```

### Manual Header Forwarding

```php
use LaravelAIEngine\Services\Node\NodeHttpClient;

// Manually specify headers to forward
$customHeaders = [
    'X-Request-Id' => 'req-123',
    'X-User-Id' => 'user-456',
    'Accept-Language' => 'en-US',
];

$client = NodeHttpClient::makeAuthenticated($node, false, 300, $customHeaders);
```

### Using getSearchHeaders()

```php
use LaravelAIEngine\Services\Node\NodeHttpClient;

// Get headers for HTTP Pool or manual requests
$forwardHeaders = NodeHttpClient::extractForwardableHeaders(request());
$headers = NodeHttpClient::getSearchHeaders($node, 'trace-123', $forwardHeaders);

// Use with HTTP Pool
$responses = Http::pool(fn ($pool) => [
    $pool->withHeaders($headers)->post($node->url . '/api/search', $data),
]);
```

## Benefits

### 1. No Authorization Conflicts
- Node authentication uses `X-Node-Token`
- User authentication uses `Authorization: Bearer` or `X-User-Authorization`
- Both can coexist without conflicts

### 2. Backward Compatibility
- Existing implementations using `Authorization: Bearer` continue to work
- Gradual migration path available

### 3. Request Context Preservation
- User authentication is forwarded as `X-User-Authorization`
- Request tracking headers are preserved
- Multi-tenancy context is maintained

### 4. Security
- Node authentication is clearly separated from user authentication
- Sensitive headers are filtered out
- Only relevant headers are forwarded

## Migration Guide

### For Existing Implementations

No immediate changes required! The system maintains backward compatibility:

1. **Current behavior:** Nodes using `Authorization: Bearer` continue to work
2. **Recommended:** Update to use `X-Node-Token` for clarity
3. **Optional:** Add request header forwarding for better context

### Updating Custom Implementations

If you have custom code that creates node requests:

**Before:**
```php
$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $token,
])->post($url, $data);
```

**After:**
```php
$response = Http::withHeaders([
    'X-Node-Token' => $token,
])->post($url, $data);
```

Or better yet, use the helper:
```php
$client = NodeHttpClient::makeAuthenticated($node);
$response = $client->post($url, $data);
```

## Testing

Run the test script to verify the changes:

```bash
php test-node-auth-header.php
```

The test verifies:
- ✓ X-Node-Token header is accepted
- ✓ Authorization Bearer header still works (backward compatibility)
- ✓ X-API-Key header still works
- ✓ NodeHttpClient sends X-Node-Token
- ✓ Header forwarding works correctly
- ✓ No conflicts between node and user authentication

## Summary

The node authentication system now uses `X-Node-Token` as the primary authentication header, with full backward compatibility for existing `Authorization: Bearer` implementations. The new `extractForwardableHeaders()` method enables intelligent request context forwarding while avoiding authentication conflicts.
