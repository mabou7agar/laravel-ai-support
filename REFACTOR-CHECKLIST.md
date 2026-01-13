# 🔄 Workflow System - Rename/Refactor Checklist

Comprehensive checklist for improving naming consistency, code organization, and maintainability of the Workflow System.

---

## 📋 Priority Levels

- **🔴 Critical**: Breaking changes, requires immediate attention
- **🟡 High**: Important improvements, should be done soon
- **🟢 Medium**: Nice to have, improves consistency
- **⚪ Low**: Optional, cosmetic improvements

---

## 1. Naming Consistency

### 1.1 Workflow Class Names

**Current State:**
- ✅ `CreateInvoiceWorkflow` - Good
- ✅ `CreateCustomerWorkflow` - Good
- ✅ `CreateProductWorkflow` - Good
- ✅ `DeclarativeInvoiceWorkflow` - Good

**Action Items:**
- [ ] 🟢 **Standardize workflow naming pattern**: `{Action}{Entity}Workflow`
  - Examples: `CreateInvoiceWorkflow`, `UpdateOrderWorkflow`, `DeleteProductWorkflow`
  - Avoid: `InvoiceCreationWorkflow`, `WorkflowForInvoice`

### 1.2 Method Names

**Current State:**
- Mixed: `createInvoice()`, `createCustomer()`, `collectData()`, `resolveEntity_customer()`

**Action Items:**
- [ ] 🟡 **Standardize entity resolution method naming**
  - Current: `resolveEntity_customer()`, `resolveEntities_products()`
  - Proposed: `resolveCustomerEntity()`, `resolveProductEntities()`
  - Rationale: More PHP-like, better IDE autocomplete

- [ ] 🟢 **Standardize final action method naming**
  - Current: `createInvoice()`, `createCustomer()`, `createProduct()`
  - Keep as-is (already consistent)
  - Pattern: `{action}{Entity}()` in camelCase

- [ ] 🟢 **Standardize step method naming**
  - Current: `collectName()`, `collectEmail()`, `askEmail()`
  - Proposed: Consistent `collect{Field}()` pattern
  - Examples: `collectName()`, `collectEmail()`, `collectPhone()`

### 1.3 Configuration Keys

**Current State:**
- Mixed: `create_if_missing`, `search_field`, `subflow`, `final_action`

**Action Items:**
- [ ] 🟢 **Standardize config key naming**
  - Keep snake_case for config arrays (Laravel convention)
  - Current naming is already consistent
  - Document the standard in a style guide

### 1.4 Context State Keys

**Current State:**
- Mixed: `customer_id`, `valid_products`, `collected_data`, `customer_name`

**Action Items:**
- [ ] 🟡 **Standardize context state key naming**
  - Pattern: `{entity}_{field}` for entity data
  - Pattern: `{action}_{data}` for workflow data
  - Examples:
    - ✅ `customer_id`, `customer_email`, `customer_name`
    - ✅ `collected_data`, `valid_products`
    - ❌ Avoid: `customerID`, `validProducts` (use snake_case)

---

## 2. Code Organization

### 2.1 File Structure

**Current State:**
```
app/AI/Workflows/
├── CreateInvoiceWorkflow.php
├── CreateCustomerWorkflow.php
├── CreateProductWorkflow.php
└── DeclarativeInvoiceWorkflow.php
```

**Action Items:**
- [ ] 🟢 **Organize workflows by domain**
  ```
  app/AI/Workflows/
  ├── Invoice/
  │   ├── CreateInvoiceWorkflow.php
  │   ├── UpdateInvoiceWorkflow.php
  │   └── DeclarativeInvoiceWorkflow.php
  ├── Customer/
  │   ├── CreateCustomerWorkflow.php
  │   └── UpdateCustomerWorkflow.php
  └── Product/
      ├── CreateProductWorkflow.php
      └── UpdateProductWorkflow.php
  ```

- [ ] 🟢 **Create base workflow classes**
  ```
  app/AI/Workflows/
  ├── Base/
  │   ├── BaseDeclarativeWorkflow.php
  │   ├── BaseManualWorkflow.php
  │   └── BaseEntityWorkflow.php
  ```

### 2.2 Trait Organization

**Current State:**
- `AutomatesSteps` - In package
- `CollectsWorkflowData` - In package

**Action Items:**
- [ ] 🟢 **Group related traits**
  ```
  src/Services/Agent/Traits/
  ├── Workflow/
  │   ├── AutomatesSteps.php
  │   ├── CollectsWorkflowData.php
  │   ├── ManagesSubworkflows.php (new)
  │   └── ResolvesEntities.php (new)
  ```

### 2.3 DTO Organization

**Current State:**
- `UnifiedActionContext` - Single large class
- `ActionResult` - Good
- `AgentResponse` - Good
- `WorkflowStep` - Good

**Action Items:**
- [ ] 🟡 **Split UnifiedActionContext into focused DTOs**
  ```php
  // Current: UnifiedActionContext (249 lines)
  
  // Proposed:
  - ActionContext (core context)
  - WorkflowContext (workflow-specific state)
  - ConversationContext (chat history)
  - WorkflowStack (stack operations)
  ```

- [ ] 🟢 **Create WorkflowConfig DTO**
  ```php
  // Instead of returning array from config()
  class WorkflowConfig
  {
      public function __construct(
          public string $goal,
          public array $fields,
          public array $entities,
          public string $finalAction,
          public array $conversationalGuidance = []
      ) {}
  }
  ```

---

## 3. Method Signatures

### 3.1 Workflow Methods

**Action Items:**
- [ ] 🟡 **Standardize entity resolution signatures**
  ```php
  // Current (inconsistent)
  protected function resolveEntity_customer(UnifiedActionContext $context): ActionResult
  protected function resolveEntities_products(UnifiedActionContext $context): ActionResult
  
  // Proposed (consistent)
  protected function resolveCustomerEntity(WorkflowContext $context): ActionResult
  protected function resolveProductEntities(WorkflowContext $context): ActionResult
  ```

- [ ] 🟢 **Add type hints to config arrays**
  ```php
  // Current
  protected function config(): array
  
  // Proposed
  protected function config(): WorkflowConfig
  // OR with PHPDoc
  /** @return array{goal: string, fields: array, entities: array, final_action: string} */
  protected function config(): array
  ```

### 3.2 Context Methods

**Action Items:**
- [ ] 🟡 **Improve context method naming**
  ```php
  // Current
  $context->set('key', $value);
  $context->get('key');
  $context->has('key');
  $context->forget('key');
  
  // Consider adding domain-specific methods
  $context->setCustomerId(int $id);
  $context->getCustomerId(): ?int;
  $context->setCollectedData(array $data);
  $context->getCollectedData(): array;
  ```

---

## 4. Documentation

### 4.1 Code Comments

**Action Items:**
- [ ] 🟡 **Add PHPDoc to all public methods**
  ```php
  /**
   * Resolve customer entity from collected data
   * 
   * Searches for customer by email in the current workspace.
   * If not found and create_if_missing is true, starts CreateCustomerWorkflow.
   * 
   * @param UnifiedActionContext $context Workflow context with collected data
   * @return ActionResult Success with customer_id or failure with error
   */
  protected function resolveEntity_customer(UnifiedActionContext $context): ActionResult
  ```

- [ ] 🟢 **Add class-level documentation**
  ```php
  /**
   * Invoice Creation Workflow
   * 
   * Creates invoices with automatic customer and product resolution.
   * Supports subworkflows for creating missing entities.
   * 
   * @see CreateCustomerWorkflow For customer creation subflow
   * @see CreateProductWorkflow For product creation subflow
   */
  class CreateInvoiceWorkflow extends AgentWorkflow
  ```

### 4.2 Inline Comments

**Action Items:**
- [ ] 🟢 **Add comments for complex logic**
  - Entity resolution logic
  - Subworkflow stack operations
  - Context state management
  - Step transitions

---

## 5. Error Handling

### 5.1 Exception Handling

**Action Items:**
- [ ] 🟡 **Create custom workflow exceptions**
  ```php
  namespace LaravelAIEngine\Exceptions\Workflow;
  
  class WorkflowException extends \Exception {}
  class WorkflowNotFoundException extends WorkflowException {}
  class WorkflowStepNotFoundException extends WorkflowException {}
  class EntityResolutionException extends WorkflowException {}
  class SubworkflowException extends WorkflowException {}
  ```

- [ ] 🟡 **Improve error messages**
  ```php
  // Current
  return ActionResult::failure(error: 'Customer not found');
  
  // Proposed
  return ActionResult::failure(
      error: "Customer with email '{$email}' not found in workspace {$workspace}",
      metadata: [
          'entity' => 'customer',
          'search_field' => 'email',
          'search_value' => $email,
          'workspace' => $workspace,
      ]
  );
  ```

### 5.2 Validation

**Action Items:**
- [ ] 🟡 **Add input validation**
  ```php
  protected function createInvoice(UnifiedActionContext $context): ActionResult
  {
      // Add validation
      if (!$context->has('customer_id')) {
          throw new EntityResolutionException('customer_id is required');
      }
      
      if (empty($context->get('valid_products'))) {
          throw new EntityResolutionException('At least one product is required');
      }
      
      // ... rest of method
  }
  ```

---

## 6. Testing

### 6.1 Test Organization

**Action Items:**
- [ ] 🟡 **Organize tests by workflow type**
  ```
  tests/Feature/Workflows/
  ├── Invoice/
  │   ├── CreateInvoiceWorkflowTest.php
  │   └── DeclarativeInvoiceWorkflowTest.php
  ├── Customer/
  │   └── CreateCustomerWorkflowTest.php
  └── Integration/
      ├── ChatServiceWorkflowTest.php
      └── SubworkflowIntegrationTest.php
  ```

### 6.2 Test Naming

**Action Items:**
- [ ] 🟢 **Standardize test method naming**
  ```php
  // Pattern: test_{action}_{scenario}_{expected_result}
  
  public function test_create_invoice_with_existing_customer_succeeds()
  public function test_create_invoice_with_new_customer_starts_subworkflow()
  public function test_resolve_customer_entity_when_not_found_returns_failure()
  ```

---

## 7. Performance

### 7.1 Caching

**Action Items:**
- [ ] 🟡 **Add cache tags for better invalidation**
  ```php
  // Current
  Cache::put("agent_context:{$sessionId}", $context->toArray(), $ttl);
  
  // Proposed
  Cache::tags(['workflow', "session:{$sessionId}"])
       ->put("agent_context:{$sessionId}", $context->toArray(), $ttl);
  ```

- [ ] 🟢 **Add cache warming for workflows**
  ```php
  // Pre-load workflow definitions
  Cache::remember('workflow:definitions', 3600, function() {
      return WorkflowRegistry::getAllDefinitions();
  });
  ```

### 7.2 Query Optimization

**Action Items:**
- [ ] 🟡 **Add eager loading to entity resolution**
  ```php
  // Current
  $customer = Customer::where('email', $email)->first();
  
  // Proposed
  $customer = Customer::with(['user', 'workspace'])
      ->where('email', $email)
      ->where('workspace', $workspace)
      ->first();
  ```

---

## 8. Configuration

### 8.1 Config Files

**Action Items:**
- [ ] 🟡 **Create dedicated workflow config**
  ```php
  // config/ai-workflows.php
  return [
      'cache_ttl' => env('WORKFLOW_CACHE_TTL', 86400),
      'max_stack_depth' => env('WORKFLOW_MAX_STACK_DEPTH', 5),
      'session_timeout' => env('WORKFLOW_SESSION_TIMEOUT', 3600),
      
      'workflows' => [
          'invoice' => [
              'create' => CreateInvoiceWorkflow::class,
              'update' => UpdateInvoiceWorkflow::class,
          ],
          'customer' => [
              'create' => CreateCustomerWorkflow::class,
          ],
      ],
  ];
  ```

### 8.2 Environment Variables

**Action Items:**
- [ ] 🟢 **Document all workflow-related env vars**
  ```bash
  # .env.example
  
  # Workflow System
  WORKFLOW_CACHE_TTL=86400
  WORKFLOW_MAX_STACK_DEPTH=5
  WORKFLOW_SESSION_TIMEOUT=3600
  WORKFLOW_DEBUG_MODE=false
  ```

---

## 9. Logging

### 9.1 Log Consistency

**Action Items:**
- [ ] 🟡 **Standardize log messages**
  ```php
  // Pattern: [Workflow] Action - Details
  
  Log::info('[Workflow] Started', [
      'workflow' => CreateInvoiceWorkflow::class,
      'session_id' => $sessionId,
      'user_id' => $userId,
  ]);
  
  Log::info('[Workflow] Step executed', [
      'workflow' => CreateInvoiceWorkflow::class,
      'step' => 'collect_customer_data',
      'result' => 'success',
  ]);
  
  Log::info('[Workflow] Completed', [
      'workflow' => CreateInvoiceWorkflow::class,
      'duration_ms' => $duration,
      'result' => $result,
  ]);
  ```

### 9.2 Log Levels

**Action Items:**
- [ ] 🟢 **Use appropriate log levels**
  ```php
  Log::debug('[Workflow] Context state', $context->toArray());
  Log::info('[Workflow] Step executed', $stepInfo);
  Log::warning('[Workflow] Entity not found', $entityInfo);
  Log::error('[Workflow] Failed', $errorInfo);
  ```

---

## 10. Backward Compatibility

### 10.1 Breaking Changes

**Action Items:**
- [ ] 🔴 **Document all breaking changes**
  - Method signature changes
  - Class renames
  - Config structure changes
  - Migration guide for existing workflows

- [ ] 🔴 **Create deprecation notices**
  ```php
  /**
   * @deprecated Use resolveCustomerEntity() instead
   * @see resolveCustomerEntity()
   */
  protected function resolveEntity_customer(UnifiedActionContext $context): ActionResult
  {
      trigger_error(
          'resolveEntity_customer() is deprecated, use resolveCustomerEntity()',
          E_USER_DEPRECATED
      );
      
      return $this->resolveCustomerEntity($context);
  }
  ```

### 10.2 Migration Path

**Action Items:**
- [ ] 🟡 **Create migration guide**
  - Document all changes
  - Provide before/after examples
  - Include automated migration scripts where possible

---

## 11. Code Quality

### 11.1 Static Analysis

**Action Items:**
- [ ] 🟡 **Add PHPStan/Psalm configuration**
  ```yaml
  # phpstan.neon
  parameters:
      level: 8
      paths:
          - src/Services/Agent
          - app/AI/Workflows
  ```

- [ ] 🟢 **Fix all static analysis issues**
  - Add missing type hints
  - Fix mixed return types
  - Remove unused variables

### 11.2 Code Style

**Action Items:**
- [ ] 🟢 **Run PHP CS Fixer**
  ```bash
  php-cs-fixer fix src/Services/Agent
  php-cs-fixer fix app/AI/Workflows
  ```

- [ ] 🟢 **Enforce consistent formatting**
  - PSR-12 compliance
  - Consistent array syntax
  - Consistent string quotes

---

## 12. Security

### 12.1 Input Validation

**Action Items:**
- [ ] 🟡 **Validate all user inputs**
  ```php
  protected function collectEmail(UnifiedActionContext $context): ActionResult
  {
      $email = $this->extractEmailFromMessage($context);
      
      // Validate email format
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          return ActionResult::needsUserInput(
              message: 'Please provide a valid email address'
          );
      }
      
      // Sanitize
      $email = filter_var($email, FILTER_SANITIZE_EMAIL);
      
      $context->set('customer_email', $email);
      return ActionResult::success();
  }
  ```

### 12.2 Authorization

**Action Items:**
- [ ] 🟡 **Add workflow authorization checks**
  ```php
  class CreateInvoiceWorkflow extends AgentWorkflow
  {
      protected function authorize(UnifiedActionContext $context): bool
      {
          $user = User::find($context->userId);
          
          return $user && $user->can('create', Invoice::class);
      }
  }
  ```

---

## 13. Internationalization

### 13.1 Message Localization

**Action Items:**
- [ ] 🟢 **Extract hardcoded messages to language files**
  ```php
  // Current
  return ActionResult::needsUserInput(
      message: 'What is the customer\'s email address?'
  );
  
  // Proposed
  return ActionResult::needsUserInput(
      message: __('workflows.invoice.prompts.customer_email')
  );
  ```

- [ ] 🟢 **Create language files**
  ```php
  // resources/lang/en/workflows.php
  return [
      'invoice' => [
          'prompts' => [
              'customer_email' => 'What is the customer\'s email address?',
              'customer_phone' => 'What is the customer\'s phone? (Optional - type \'skip\')',
          ],
      ],
  ];
  ```

---

## 14. Monitoring

### 14.1 Metrics

**Action Items:**
- [ ] 🟢 **Add workflow metrics**
  ```php
  // Track workflow execution
  Metrics::increment('workflow.started', [
      'workflow' => CreateInvoiceWorkflow::class,
  ]);
  
  Metrics::timing('workflow.duration', $duration, [
      'workflow' => CreateInvoiceWorkflow::class,
      'success' => $success,
  ]);
  
  Metrics::increment('workflow.completed', [
      'workflow' => CreateInvoiceWorkflow::class,
      'result' => $result,
  ]);
  ```

### 14.2 Health Checks

**Action Items:**
- [ ] 🟢 **Add workflow health endpoint**
  ```php
  // GET /api/v1/workflows/health
  {
      "status": "healthy",
      "active_workflows": 5,
      "cache_hit_rate": 0.95,
      "avg_completion_time_ms": 1250
  }
  ```

---

## Implementation Priority

### Phase 1: Critical (Week 1)
- [ ] Document breaking changes
- [ ] Create custom exceptions
- [ ] Add input validation
- [ ] Improve error messages

### Phase 2: High Priority (Week 2)
- [ ] Standardize entity resolution method naming
- [ ] Split UnifiedActionContext into focused DTOs
- [ ] Add PHPDoc to all public methods
- [ ] Organize tests by workflow type

### Phase 3: Medium Priority (Week 3-4)
- [ ] Organize workflows by domain
- [ ] Create base workflow classes
- [ ] Add cache tags
- [ ] Create dedicated workflow config

### Phase 4: Low Priority (Ongoing)
- [ ] Extract messages to language files
- [ ] Add workflow metrics
- [ ] Create health check endpoint
- [ ] Improve code style consistency

---

## Checklist Summary

**Total Items**: 60+
- 🔴 Critical: 2
- 🟡 High: 15
- 🟢 Medium: 30+
- ⚪ Low: 13+

**Estimated Effort**: 4-6 weeks for complete implementation

---

## Notes

- This checklist should be reviewed and updated regularly
- Each item should be tracked in a separate issue/ticket
- Breaking changes require version bump (major version)
- All changes should include tests and documentation updates
- Consider creating a feature branch for major refactoring work

---

**Last Updated**: January 12, 2026  
**Status**: Draft - Ready for Review
