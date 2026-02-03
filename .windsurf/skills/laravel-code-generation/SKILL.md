---
name: laravel-code-generation
description: Generate Laravel code using AI with best practices and modern patterns. Use this when the user wants to create controllers, models, services, repositories, DTOs, middleware, or any Laravel component with proper structure and validation.
---

# Laravel Code Generation

Generate production-ready Laravel code following best practices, design patterns, and modern Laravel conventions.

## When to Use This Skill

- User wants to create a new Laravel component (controller, model, service, etc.)
- User needs CRUD operations with validation
- User wants to implement design patterns (repository, service layer, DTO)
- User needs API resources with relationships
- User wants middleware or custom classes

## Supported Code Types

### Core Components
- **Controllers**: CRUD, API, resource controllers
- **Models**: Eloquent models with relationships, scopes, accessors
- **Middleware**: Authentication, rate limiting, custom logic
- **Services**: Business logic layer
- **Repositories**: Data access layer with interface

### Data Transfer
- **DTOs**: Type-safe data transfer objects
- **Resources**: API resources with transformations
- **Requests**: Form request validation

### Database
- **Migrations**: Schema design with relationships
- **Seeders**: Test data generation
- **Factories**: Model factories for testing

### Testing
- **Unit Tests**: Model and service tests
- **Feature Tests**: API and integration tests

## Usage Examples

### Generate CRUD Controller
```php
// Request: "Create a complete CRUD controller for Product model with validation"

// Generated files:
// - app/Http/Controllers/ProductController.php
// - app/Http/Requests/StoreProductRequest.php
// - app/Http/Requests/UpdateProductRequest.php
// - tests/Feature/ProductControllerTest.php
```

### Generate Service with Repository
```php
// Request: "Create a service class for order processing with repository pattern"

// Generated files:
// - app/Services/OrderService.php
// - app/Repositories/OrderRepository.php
// - app/Contracts/OrderRepositoryInterface.php
// - app/DTOs/OrderDTO.php
```

### Generate API Resource
```php
// Request: "Generate API resource for User with relationships to posts and comments"

// Generated files:
// - app/Http/Resources/UserResource.php
// - app/Http/Resources/PostResource.php
// - app/Http/Resources/CommentResource.php
```

## Code Generation Features

### Automatic Includes
- ✅ Proper namespaces
- ✅ Import statements
- ✅ Type hints and return types
- ✅ PHPDoc comments
- ✅ Validation rules
- ✅ Relationships
- ✅ Authorization checks
- ✅ Error handling

### Best Practices
- ✅ Single Responsibility Principle
- ✅ Dependency Injection
- ✅ Interface segregation
- ✅ Repository pattern
- ✅ Service layer pattern
- ✅ DTO pattern
- ✅ Resource pattern

## Configuration

The code generator follows your project's conventions:

```php
// config/ai-engine.php
'code_generation' => [
    'namespace_prefix' => 'App',
    'use_strict_types' => true,
    'generate_tests' => true,
    'follow_psr12' => true,
],
```

## Tips for Better Results

1. **Be Specific**: "Create CRUD controller for Product with image upload" is better than "create controller"
2. **Mention Features**: Specify validation, relationships, authorization needs
3. **Specify Patterns**: Mention if you want repository pattern, service layer, etc.
4. **Include Context**: Mention related models or business logic
5. **Request Tests**: Ask for test generation if needed

## Example Prompts

- "Create a complete CRUD controller for User model with validation and authorization"
- "Generate a service class for payment processing with Stripe integration"
- "Build a repository pattern for Product model with caching"
- "Create API resource for Order with nested items and customer data"
- "Generate middleware for API rate limiting with Redis"
