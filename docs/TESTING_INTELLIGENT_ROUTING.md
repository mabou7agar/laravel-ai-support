# Testing Intelligent Routing System

## Integration with ChatService

The new `MessageAnalyzer` and `AgentOrchestrator` are already integrated with `ChatService`. Here's the flow:

```
ChatService::processMessage()
    ↓
Checks: useActions && (hasActiveWorkflow || looksLikeActionRequest)
    ↓
AgentOrchestrator::process()
    ↓
MessageAnalyzer::analyze() → Intelligent routing
    ↓
Routes to appropriate handler
    ↓
Returns AIResponse
```

**Integration Point:** `ChatService.php` line 279-306

---

## End-to-End Test Scenarios

### Test 1: Basic Workflow Creation

**Endpoint:** `POST /ai-demo/chat/send`

**Headers:**
```
Authorization: Bearer 3|fBDXQOXOnfWL7uzYDfOZEcl59y9PgPQRND8hx6Dccaa9887e
Content-Type: application/json
```

**Request 1 - Start Workflow:**
```json
{
  "message": "create invoice",
  "session_id": "test-session-001"
}
```

**Expected Response:**
```json
{
  "content": "Who is the customer?",
  "metadata": {
    "workflow_active": true,
    "workflow_class": "App\\Workflows\\InvoiceWorkflow",
    "agent_strategy": "agent_mode"
  }
}
```

**Request 2 - Provide Customer:**
```json
{
  "message": "John Smith",
  "session_id": "test-session-001"
}
```

**Expected Response:**
```json
{
  "content": "Great! What products would you like to add?",
  "metadata": {
    "workflow_active": true
  }
}
```

---

### Test 2: Mid-Workflow Question (Seamless Switching)

**Request 1 - Start Workflow:**
```json
{
  "message": "create invoice",
  "session_id": "test-session-002"
}
```

**Request 2 - Ask Unrelated Question:**
```json
{
  "message": "what's the weather today?",
  "session_id": "test-session-002"
}
```

**Expected Behavior:**
- MessageAnalyzer detects: `type=normal_question, action=answer_and_resume_workflow`
- System answers question
- Workflow remains active
- Response includes workflow reminder

**Expected Response:**
```json
{
  "content": "I don't have access to weather data, but I can help with other questions.\n\nContinuing with InvoiceWorkflow... Who is the customer?",
  "metadata": {
    "workflow_active": true,
    "workflow_paused_for_question": true
  }
}
```

**Request 3 - Continue Workflow:**
```json
{
  "message": "John Smith",
  "session_id": "test-session-002"
}
```

**Expected Response:**
```json
{
  "content": "Great! What products would you like to add?",
  "metadata": {
    "workflow_active": true
  }
}
```

---

### Test 3: Sub-Workflow Detection

**Request 1 - Start Invoice:**
```json
{
  "message": "create invoice",
  "session_id": "test-session-003"
}
```

**Request 2 - Provide Customer:**
```json
{
  "message": "John Smith",
  "session_id": "test-session-003"
}
```

**Request 3 - Request Sub-Workflow:**
```json
{
  "message": "create a new product first - Laptop Pro",
  "session_id": "test-session-003"
}
```

**Expected Behavior:**
- MessageAnalyzer detects: `type=sub_workflow, action=start_sub_workflow`
- Parent workflow (Invoice) is paused
- Product workflow starts
- Parent state saved in context

**Expected Response:**
```json
{
  "content": "Creating new product... What's the product name?",
  "metadata": {
    "workflow_active": true,
    "workflow_class": "App\\Workflows\\ProductWorkflow",
    "parent_workflow": "App\\Workflows\\InvoiceWorkflow"
  }
}
```

**Request 4 - Complete Product:**
```json
{
  "message": "Laptop Pro",
  "session_id": "test-session-003"
}
```

**Request 5 - After Product Completion:**
- System should automatically resume Invoice workflow
- Use created product in invoice

---

### Test 4: Workflow Cancellation

**Request 1 - Start Workflow:**
```json
{
  "message": "create invoice",
  "session_id": "test-session-004"
}
```

**Request 2 - Cancel:**
```json
{
  "message": "cancel",
  "session_id": "test-session-004"
}
```

**Expected Behavior:**
- MessageAnalyzer detects: `type=cancel, action=cancel_workflow`
- Workflow state cleared
- Context reset

**Expected Response:**
```json
{
  "content": "Workflow 'InvoiceWorkflow' has been canceled. How can I help you?",
  "metadata": {
    "workflow_active": false
  }
}
```

---

### Test 5: Multi-Language Support

**Request 1 - Start Workflow:**
```json
{
  "message": "create invoice",
  "session_id": "test-session-005"
}
```

**Request 2 - Confirm in Arabic:**
```json
{
  "message": "نعم",
  "session_id": "test-session-005"
}
```

**Expected Behavior:**
- MessageAnalyzer detects confirmation (length <= 5 chars)
- Works without English keyword matching
- Workflow continues

---

### Test 6: Simple Questions (No Workflow)

**Request:**
```json
{
  "message": "hello",
  "session_id": "test-session-006"
}
```

**Expected Behavior:**
- MessageAnalyzer detects: `type=simple_answer, action=answer_directly`
- No workflow started
- Direct response

**Expected Response:**
```json
{
  "content": "Hello! How can I help you today?",
  "metadata": {
    "workflow_active": false
  }
}
```

---

## Testing with cURL

### Basic Workflow Test:

```bash
# Start workflow
curl -X POST https://dash.test/ai-demo/chat/send \
  -H "Authorization: Bearer 3|fBDXQOXOnfWL7uzYDfOZEcl59y9PgPQRND8hx6Dccaa9887e" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "create invoice",
    "session_id": "test-001"
  }'

# Provide customer
curl -X POST https://dash.test/ai-demo/chat/send \
  -H "Authorization: Bearer 3|fBDXQOXOnfWL7uzYDfOZEcl59y9PgPQRND8hx6Dccaa9887e" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "John Smith",
    "session_id": "test-001"
  }'
```

### Mid-Workflow Question Test:

```bash
# Start workflow
curl -X POST https://dash.test/ai-demo/chat/send \
  -H "Authorization: Bearer 3|fBDXQOXOnfWL7uzYDfOZEcl59y9PgPQRND8hx6Dccaa9887e" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "create invoice",
    "session_id": "test-002"
  }'

# Ask question mid-workflow
curl -X POST https://dash.test/ai-demo/chat/send \
  -H "Authorization: Bearer 3|fBDXQOXOnfWL7uzYDfOZEcl59y9PgPQRND8hx6Dccaa9887e" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "what is an invoice?",
    "session_id": "test-002"
  }'

# Continue workflow
curl -X POST https://dash.test/ai-demo/chat/send \
  -H "Authorization: Bearer 3|fBDXQOXOnfWL7uzYDfOZEcl59y9PgPQRND8hx6Dccaa9887e" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "John Smith",
    "session_id": "test-002"
  }'
```

---

## Debugging

### Check Logs:

```bash
tail -f storage/logs/laravel.log | grep -E "(Message analyzed|Continuing workflow|Answering question)"
```

### Expected Log Output:

```
[2026-01-15 01:45:00] ai-engine.INFO: Message analyzed {"type":"workflow_continuation","action":"continue_workflow","confidence":0.95,"reasoning":"Answering question about customer"}

[2026-01-15 01:45:05] ai-engine.INFO: Message analyzed {"type":"normal_question","action":"answer_and_resume_workflow","confidence":0.85,"reasoning":"User asking question mid-workflow"}

[2026-01-15 01:45:10] ai-engine.INFO: Answering question mid-workflow {"workflow":"App\\Workflows\\InvoiceWorkflow","question":"what is an invoice?"}

[2026-01-15 01:45:15] ai-engine.INFO: Continuing workflow {"workflow":"App\\Workflows\\InvoiceWorkflow","step":"collect_customer"}
```

---

## Validation Checklist

- [ ] Workflow starts correctly
- [ ] Workflow continues with user input
- [ ] Mid-workflow questions are answered
- [ ] Workflow resumes after question
- [ ] Sub-workflows can be started
- [ ] Parent workflow resumes after sub-workflow
- [ ] Cancellation clears workflow state
- [ ] Multi-language confirmations work
- [ ] Simple greetings work without workflow
- [ ] Context is preserved across messages
- [ ] Logs show correct message analysis

---

## Common Issues

### Issue 1: Workflow Not Starting

**Symptom:** Message not triggering workflow

**Check:**
```bash
# Check workflow config
php artisan tinker
>>> config('ai-agent.workflows')
```

**Solution:** Ensure workflow triggers are configured in `config/ai-agent.php`

### Issue 2: Questions Breaking Workflow

**Symptom:** Asking question cancels workflow

**Check Logs:**
```bash
grep "Message analyzed" storage/logs/laravel.log
```

**Expected:** Should see `action=answer_and_resume_workflow`

**If Not:** MessageAnalyzer might be misclassifying. Check `looksLikeQuestion()` logic.

### Issue 3: Context Not Persisting

**Symptom:** Workflow state lost between messages

**Check:**
```bash
php artisan tinker
>>> Cache::get('agent_context:test-001')
```

**Solution:** Ensure `ContextManager::save()` is called after each operation.

---

## Performance Metrics

| Metric | Target | Actual |
|--------|--------|--------|
| Message analysis time | < 50ms | TBD |
| Workflow continuation | < 200ms | TBD |
| Mid-workflow question | < 1s | TBD |
| Total response time | < 2s | TBD |
| AI calls per message | 1 | ✅ |

---

## Next Steps

1. Run basic workflow test
2. Test mid-workflow questions
3. Test sub-workflow functionality
4. Test cancellation
5. Test multi-language support
6. Monitor logs for issues
7. Optimize based on results
