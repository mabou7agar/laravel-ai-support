# Integration Tests

This directory contains integration tests that test the complete workflow using real HTTP requests, similar to the curl commands used during manual testing.

## Test Files

### 1. WorkflowIntegrationTest.php
Laravel test case that uses Laravel's HTTP testing features to test the API endpoints.

**Run with:**
```bash
cd /Volumes/M.2/Work/Bites/inbusiness
php artisan test tests/Integration/WorkflowIntegrationTest.php
```

**Or specific test:**
```bash
php artisan test --filter it_creates_invoice_with_correct_price_display
php artisan test --filter it_asks_for_category_name_not_id
php artisan test --filter it_handles_email_validation_naturally
```

### 2. run-workflow-tests.php
Standalone PHP script that can be run directly without Laravel test framework. Uses curl to make real HTTP requests.

**Configuration:**
Edit the script to set your environment:
```php
$BASE_URL = 'https://dash.test/ai-demo/chat/send';
$AUTH_TOKEN = 'your-bearer-token';
$CSRF_TOKEN = 'your-csrf-token';
```

**Run with:**
```bash
cd /Volumes/M.2/Work/laravel-ai-demo/packages/laravel-ai-engine
php tests/Integration/run-workflow-tests.php
```

**Or make executable:**
```bash
chmod +x tests/Integration/run-workflow-tests.php
./tests/Integration/run-workflow-tests.php
```

## What These Tests Cover

### Test 1: Price Display in Confirmation Messages
- Creates invoice with new customer and product
- Navigates through entire workflow
- Verifies prices are displayed correctly (not $0)
- Checks product names and quantities
- Validates total calculation

**Expected behavior:**
- ✓ No $0 prices in final message
- ✓ Displays actual prices ($999.99, etc.)
- ✓ Includes product names
- ✓ Shows quantities (× 2, × 3)
- ✓ Displays total amount

### Test 2: FriendlyName in Category Prompts
- Creates invoice with product requiring category
- Checks that AI asks for "category name" not "category ID"
- Validates natural language prompts

**Expected behavior:**
- ✓ Does NOT ask for "category ID"
- ✓ Asks for category naturally
- ✓ Uses friendlyName from field config

### Test 3: AI-Driven Validation
- Tests email validation handling
- Provides invalid email format
- Verifies AI handles it conversationally (no hard errors)
- Provides valid email and continues

**Expected behavior:**
- ✓ No hard error on invalid email
- ✓ AI handles validation conversationally
- ✓ Accepts valid email and continues
- ✓ Workflow completes successfully

### Test 4: Multiple Products with Prices
- Creates invoice with multiple products (2 Laptops, 3 Mice)
- Verifies all products are displayed
- Checks quantities for each product
- Validates no $0 prices
- Confirms total is shown

**Expected behavior:**
- ✓ Shows all products
- ✓ Shows correct quantities
- ✓ No $0 prices
- ✓ Displays total
- ✓ Workflow completes

### Test 5: Existing Products
- Creates a product first
- Creates invoice using existing product
- Verifies existing product includes prices

**Expected behavior:**
- ✓ Finds existing product
- ✓ Includes price from database
- ✓ No $0 prices

### Test 6: Workflow Completion
- Tests complete workflow end-to-end
- Verifies no errors occur
- Confirms successful completion

**Expected behavior:**
- ✓ No errors during workflow
- ✓ All steps complete successfully
- ✓ Final success message displayed

## Output Example

When running `run-workflow-tests.php`, you'll see colored output:

```
═══════════════════════════════════════════════════════════════════
  TEST 1: Price Display in Confirmation Messages
═══════════════════════════════════════════════════════════════════

Creating invoice with new customer and product...
  ✓ PASS - API responds successfully
  ✓ PASS - No $0 prices in final message
  ✓ PASS - Displays actual prices
  ✓ PASS - Includes product name
  ✓ PASS - Workflow completed

═══════════════════════════════════════════════════════════════════
  TEST 2: FriendlyName in Category Prompts
═══════════════════════════════════════════════════════════════════

Creating invoice with product that needs category...
  ✓ PASS - Does NOT ask for "category ID"
  ✓ PASS - Asks for category naturally
  ✓ PASS - Found category prompt

...

═══════════════════════════════════════════════════════════════════
  TEST SUMMARY
═══════════════════════════════════════════════════════════════════

  Total Tests: 18
  Passed: 18
  Failed: 0
```

## Troubleshooting

### Authentication Issues
If you get 401 errors:
1. Update `AUTH_TOKEN` with a valid bearer token
2. Update `CSRF_TOKEN` with a valid CSRF token
3. Check that your session is valid

### Connection Issues
If you get connection errors:
1. Verify `BASE_URL` is correct
2. Check that the server is running
3. Verify SSL certificates (or disable with `CURLOPT_SSL_VERIFYPEER`)

### Workflow Not Completing
If tests timeout or don't complete:
1. Increase `$maxSteps` in the test
2. Check logs: `storage/logs/ai-engine.log`
3. Run manually with curl to debug
4. Check that all required services are running

### Rate Limiting
If you hit rate limits:
1. Increase `sleep()` duration between requests
2. Run tests one at a time
3. Check API rate limit settings

## Comparison with Manual Testing

These tests replicate the manual curl commands we used:

**Manual curl:**
```bash
curl 'https://dash.test/ai-demo/chat/send' \
  -H 'authorization: Bearer TOKEN' \
  -H 'content-type: application/json' \
  --data-raw '{"message":"create invoice for Test User with 1 Laptop","session_id":"test-123"}'
```

**Automated test:**
```php
$response = sendRequest($BASE_URL, [
    'message' => 'create invoice for Test User with 1 Laptop',
    'session_id' => $sessionId,
    'memory' => true,
    'actions' => true,
], $AUTH_TOKEN, $CSRF_TOKEN);
```

## CI/CD Integration

Add to your CI pipeline:

```yaml
# .github/workflows/tests.yml
- name: Run Integration Tests
  run: |
    php tests/Integration/run-workflow-tests.php
```

Or with Laravel:

```yaml
- name: Run Integration Tests
  run: |
    php artisan test tests/Integration/WorkflowIntegrationTest.php
```

## Best Practices

1. **Run before deploying** - Always run integration tests before deploying to production
2. **Update tokens** - Keep authentication tokens up to date
3. **Check logs** - Review logs after failed tests
4. **Isolate sessions** - Each test uses unique session IDs to avoid conflicts
5. **Clean up** - Tests should clean up created data (or use test database)

## Related Documentation

- Unit Tests: `tests/Feature/Workflows/README.md`
- API Documentation: `docs/API.md`
- Workflow Guide: `docs/guides/WORKFLOWS.md`
