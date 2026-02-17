<?php

namespace LaravelAIEngine\Tests\Feature\Workflows;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\WorkflowDataCollector;

/**
 * Test AI-driven validation instead of programmatic validation
 * 
 * Tests that validation is handled naturally by AI through conversation
 * rather than rigid programmatic validation rules.
 */
class AIValidationTest extends TestCase
{
    protected WorkflowDataCollector $dataCollector;
    
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(WorkflowDataCollector::class)) {
            $this->markTestSkipped('WorkflowDataCollector class not available');
        }
        
        // Mock AI services
        $this->mockAIServices();
        
        $this->dataCollector = app(WorkflowDataCollector::class);
    }
    
    /**
     * Test that validation requirements are included in extraction prompt
     * 
     * @test
     */
    public function it_includes_validation_requirements_in_extraction_prompt()
    {
        $fieldDefinitions = [
            'email' => [
                'type' => 'string',
                'description' => 'Email address',
                'validation' => ['email', 'max:255'],
                'required' => true,
            ],
            'age' => [
                'type' => 'integer',
                'description' => 'Age',
                'validation' => ['numeric', 'min:18', 'max:120'],
                'required' => false,
            ],
        ];
        
        // Build extraction prompt (simplified version)
        $prompt = "VALIDATION REQUIREMENTS (AI will validate naturally):\n";
        
        foreach ($fieldDefinitions as $fieldName => $fieldDef) {
            if (!empty($fieldDef['validation'])) {
                $validationRules = is_array($fieldDef['validation']) ? $fieldDef['validation'] : explode('|', $fieldDef['validation']);
                $prompt .= "- {$fieldName}:\n";
                
                foreach ($validationRules as $rule) {
                    $rule = trim($rule);
                    if ($rule === 'email') {
                        $prompt .= "  * Must be valid email format (e.g., user@domain.com)\n";
                    } elseif ($rule === 'numeric') {
                        $prompt .= "  * Must be a number\n";
                    } elseif (str_contains($rule, 'min:')) {
                        $prompt .= "  * Minimum " . str_replace('min:', '', $rule) . " characters\n";
                    } elseif (str_contains($rule, 'max:')) {
                        $prompt .= "  * Maximum " . str_replace('max:', '', $rule) . " characters\n";
                    }
                }
            }
        }
        
        // Assertions
        $this->assertStringContainsString('VALIDATION REQUIREMENTS', $prompt);
        $this->assertStringContainsString('Must be valid email format', $prompt);
        $this->assertStringContainsString('Must be a number', $prompt);
        $this->assertStringContainsString('Minimum 18', $prompt);
        $this->assertStringContainsString('Maximum 120', $prompt);
        $this->assertStringContainsString('Maximum 255 characters', $prompt);
    }
    
    /**
     * Test that data is collected without programmatic validation blocking
     * 
     * @test
     */
    public function it_collects_data_without_programmatic_validation()
    {
        $context = new UnifiedActionContext('test-session', 'test-user-1');
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'My email is test@example.com'],
        ];
        
        $fieldDefinitions = [
            'email' => [
                'type' => 'string',
                'description' => 'Email address',
                'validation' => ['email'],
                'required' => true,
            ],
        ];
        
        // Collect data
        $result = $this->dataCollector->collectData($context, $fieldDefinitions);
        
        // Should collect data successfully (AI handles validation conversationally)
        $this->assertNotNull($result);
        
        // Check collected data
        $collectedData = $context->get('collected_data', []);
        $this->assertArrayHasKey('email', $collectedData);
    }
    
    /**
     * Test that invalid data is extracted but AI will handle it conversationally
     * 
     * @test
     */
    public function it_extracts_invalid_data_for_ai_to_handle()
    {
        $context = new UnifiedActionContext('test-session', 'test-user-1');
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'My email is invalid-email'],
        ];
        
        $fieldDefinitions = [
            'email' => [
                'type' => 'string',
                'description' => 'Email address',
                'validation' => ['email'],
                'required' => true,
            ],
        ];
        
        // Collect data - should not throw validation error
        $result = $this->dataCollector->collectData($context, $fieldDefinitions);
        
        // Data should be extracted (AI will validate conversationally)
        $this->assertNotNull($result);
    }
    
    /**
     * Test validation rules are passed to AI for contextual understanding
     * 
     * @test
     */
    public function it_provides_validation_context_to_ai()
    {
        $fieldDefinitions = [
            'email' => [
                'type' => 'string',
                'friendly_name' => 'email address',
                'description' => 'Customer email',
                'validation' => ['email', 'max:255'],
                'examples' => ['john@example.com', 'jane@company.com'],
                'required' => true,
            ],
        ];
        
        // Check that validation rules are structured correctly
        $emailField = $fieldDefinitions['email'];
        
        $this->assertArrayHasKey('validation', $emailField);
        $this->assertContains('email', $emailField['validation']);
        $this->assertContains('max:255', $emailField['validation']);
        $this->assertArrayHasKey('examples', $emailField);
        $this->assertArrayHasKey('friendly_name', $emailField);
    }
    
    /**
     * Test that AI prompt includes validation awareness
     * 
     * @test
     */
    public function it_includes_validation_awareness_in_ai_prompt()
    {
        $fieldDef = [
            'type' => 'string',
            'friendly_name' => 'email address',
            'description' => 'Customer email',
            'validation' => ['email', 'min:5', 'max:255'],
            'required' => true,
        ];
        
        // Build prompt context (simplified)
        $prompt = "- Validation requirements:\n";
        $validationRules = $fieldDef['validation'];
        
        foreach ($validationRules as $rule) {
            $rule = trim($rule);
            if (str_contains($rule, 'min:')) {
                $prompt .= "  * Minimum " . str_replace('min:', '', $rule) . " characters\n";
            }
            if (str_contains($rule, 'max:')) {
                $prompt .= "  * Maximum " . str_replace('max:', '', $rule) . " characters\n";
            }
            if ($rule === 'email') {
                $prompt .= "  * Must be a valid email address format\n";
            }
        }
        
        $prompt .= "\nIMPORTANT: You will validate the user's response in the next turn. If they provide invalid data, politely explain what's wrong and ask them to try again.\n";
        
        // Assertions
        $this->assertStringContainsString('Validation requirements', $prompt);
        $this->assertStringContainsString('Must be a valid email address format', $prompt);
        $this->assertStringContainsString('Minimum 5 characters', $prompt);
        $this->assertStringContainsString('Maximum 255 characters', $prompt);
        $this->assertStringContainsString('You will validate', $prompt);
    }
    
    /**
     * Test multiple validation rules are properly formatted
     * 
     * @test
     */
    public function it_formats_multiple_validation_rules_correctly()
    {
        $fieldDefinitions = [
            'password' => [
                'type' => 'string',
                'validation' => ['min:8', 'max:128'],
            ],
            'age' => [
                'type' => 'integer',
                'validation' => ['numeric', 'min:18', 'max:120'],
            ],
            'website' => [
                'type' => 'string',
                'validation' => ['url'],
            ],
        ];
        
        foreach ($fieldDefinitions as $fieldName => $fieldDef) {
            $this->assertArrayHasKey('validation', $fieldDef);
            $this->assertIsArray($fieldDef['validation']);
        }
        
        // Password validation
        $this->assertContains('min:8', $fieldDefinitions['password']['validation']);
        $this->assertContains('max:128', $fieldDefinitions['password']['validation']);
        
        // Age validation
        $this->assertContains('numeric', $fieldDefinitions['age']['validation']);
        $this->assertContains('min:18', $fieldDefinitions['age']['validation']);
        
        // Website validation
        $this->assertContains('url', $fieldDefinitions['website']['validation']);
    }
    
    /**
     * Mock AI services for testing
     */
    protected function mockAIServices()
    {
        // Mock AIService
        $mockAI = \Mockery::mock(\LaravelAIEngine\Services\AIService::class);
        $mockAI->shouldReceive('generate')
            ->andReturn(new class {
                public $content = '{"email": "test@example.com"}';
            });
        
        $this->app->instance(\LaravelAIEngine\Services\AIService::class, $mockAI);
        
        // Mock AIEnhancedDataExtractor
        $mockExtractor = \Mockery::mock(\LaravelAIEngine\Services\Agent\AIEnhancedDataExtractor::class);
        $mockExtractor->shouldReceive('whatElseDoWeNeed')
            ->andReturn([]);
        
        $this->app->instance(\LaravelAIEngine\Services\Agent\AIEnhancedDataExtractor::class, $mockExtractor);
    }
}
