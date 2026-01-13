# Async Workflow Support with SSE

This document explains how to use the async workflow feature with Server-Sent Events (SSE) for real-time updates in chat applications.

## Overview

The async workflow feature is an **optional enhancement** that works alongside the existing synchronous workflow processing. It allows long-running workflows to be processed in the background while providing real-time progress updates to the frontend.

## When to Use Async Mode

**Use Async Mode When:**
- Processing complex workflows (invoice creation, multi-step actions)
- Requests that may take >10 seconds
- You want to provide real-time progress updates
- You want to prevent HTTP timeouts

**Use Sync Mode When:**
- Simple chat messages
- Quick responses (<5 seconds)
- No workflow processing needed
- Simpler implementation required

## Backend Implementation

### 1. Enable Async Mode

Add `async: true` to your request:

```javascript
const response = await fetch('/ai-demo/chat/send', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer YOUR_TOKEN' },
    body: JSON.stringify({
        message: 'create invoice',
        session_id: 'session-123',
        async: true,  // Enable async mode
        actions: true,
        memory: true,
        intelligent_rag: true,
    })
});
```

### 2. Response Format

**Async Response:**
```json
{
    "success": true,
    "async": true,
    "job_id": "workflow_abc123",
    "status": "processing",
    "message": "Your request is being processed...",
    "stream_url": "https://api.example.com/ai-demo/workflow/stream/workflow_abc123",
    "status_url": "https://api.example.com/ai-demo/workflow/status/workflow_abc123"
}
```

**Sync Response (default):**
```json
{
    "success": true,
    "response": "Here is your response...",
    "actions": [],
    "sources": []
}
```

## Frontend Implementation

### Option 1: SSE Streaming (Recommended)

```javascript
// Connect to SSE stream
const eventSource = new EventSource(data.stream_url);

eventSource.onmessage = (event) => {
    const update = JSON.parse(event.data);
    
    if (update.status === 'completed') {
        displayMessage(update.response);
        eventSource.close();
    } else if (update.status === 'processing') {
        updateProgress(update.message, update.progress);
    } else if (update.status === 'failed') {
        displayError(update.error);
        eventSource.close();
    }
};
```

### Option 2: Polling

```javascript
async function pollStatus(statusUrl) {
    const poll = async () => {
        const response = await fetch(statusUrl);
        const data = await response.json();
        
        if (data.data.status === 'completed') {
            displayMessage(data.data.response);
            return;
        }
        
        if (data.data.status === 'failed') {
            displayError(data.data.error);
            return;
        }
        
        // Continue polling
        setTimeout(poll, 2000);
    };
    
    poll();
}
```

## Complete Example

See `resources/js/async-chat-example.js` for a complete implementation with:
- AsyncChatClient class
- Progress callbacks
- Error handling
- Smart mode detection
- UI helpers

## Configuration

### Queue Configuration

Ensure your queue is configured in `.env`:

```bash
QUEUE_CONNECTION=database  # or redis, sync
```

Run queue worker:

```bash
php artisan queue:work
```

### Workflow Timeouts

Configure in `config/ai-engine.php`:

```php
'workflow' => [
    'max_execution_time' => 120,  // 2 minutes
    'max_ai_calls' => 10,
],
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/ai-demo/chat/send` | POST | Send message (sync or async) |
| `/ai-demo/workflow/stream/{jobId}` | GET | SSE stream for workflow updates |
| `/ai-demo/workflow/status/{jobId}` | GET | Get workflow status (polling) |

## Status Updates

The SSE stream sends updates in this format:

```json
{
    "status": "processing",
    "progress": 50,
    "message": "Processing step 2 of 4...",
    "updated_at": "2026-01-13T13:45:00Z"
}
```

**Status Values:**
- `connected` - Stream connected
- `processing` - Workflow in progress
- `completed` - Workflow finished successfully
- `failed` - Workflow failed
- `timeout` - Request timed out

## Error Handling

```javascript
try {
    const result = await chat.sendMessage('create invoice', { async: true });
    displayMessage(result.response);
} catch (error) {
    if (error.message === 'Request timed out') {
        displayError('The request took too long. Please try again.');
    } else {
        displayError('An error occurred: ' + error.message);
    }
}
```

## Performance Considerations

**Async Mode:**
- ✅ No HTTP timeout issues
- ✅ Real-time progress updates
- ✅ Better user experience for long tasks
- ⚠️ Requires queue worker
- ⚠️ More complex implementation

**Sync Mode:**
- ✅ Simple implementation
- ✅ No queue required
- ✅ Immediate response for quick tasks
- ⚠️ HTTP timeout risk for long tasks
- ⚠️ No progress updates

## Backward Compatibility

The async feature is **completely optional**. If you don't pass `async: true`, the system works exactly as before with synchronous processing.

```javascript
// Old code still works - no changes needed
const response = await fetch('/ai-demo/chat/send', {
    method: 'POST',
    body: JSON.stringify({ message: 'hello' })
});
```

## Testing

### Test Async Mode

```bash
curl -X POST https://dash.test/ai-demo/chat/send \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "create invoice",
    "session_id": "test-123",
    "async": true,
    "actions": true
  }'
```

### Test SSE Stream

```bash
curl -N https://dash.test/ai-demo/workflow/stream/workflow_abc123 \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

## Troubleshooting

**Issue: Queue not processing**
```bash
# Check queue status
php artisan queue:work --once

# Check failed jobs
php artisan queue:failed
```

**Issue: SSE connection drops**
- Check nginx/apache buffering settings
- Ensure `X-Accel-Buffering: no` header is set
- Increase proxy timeout settings

**Issue: Workflow times out**
- Increase `max_execution_time` in config
- Check queue worker is running
- Review workflow complexity

## Next Steps

1. ✅ Implement frontend SSE client
2. ✅ Configure queue worker
3. ✅ Test with long-running workflows
4. ✅ Add progress indicators to UI
5. ✅ Monitor queue performance

For more examples, see `resources/js/async-chat-example.js`.
