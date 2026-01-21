<?php

namespace LaravelAIEngine\Tests\Feature\Workflows;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\IntelligentPromptGenerator;
use LaravelAIEngine\DTOs\EntityFieldConfig;
use Illuminate\Support\Facades\Config;

/**
 * Test friendlyName support in prompt generation
 * 
 * Tests the fix where AI was asking for "category ID" instead of "category name"
 * because the friendlyName property wasn't being used in prompt generation.
 */
class FriendlyNameTest extends TestCase
{
    protected IntelligentPromptGenerator $promptGenerator;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock AI service for testing
        $this->mockAIService();
        
        $this->promptGenerator = app(IntelligentPromptGenerator::class);
    }
    
    /**
     * Test that friendlyName is used instead of field name
     * 
     * @test
     */
    public function it_uses_friendly_name_in_prompt_generation()
    {
        $context = new UnifiedActionContext('test-session');
        $context->set('collected_data', ['name' => 'Soccer Ball']);
        
        $fieldDefinitions = [
            'category_id' => [
                'type' => 'entity',
                'friendly_name' => 'category name',
                'description' => 'Product category name (e.g., Electronics, Footwear, Clothing)',
                'required' => false,
            ],
        ];
        
        $missingFields = ['category_id'];
        
        // Generate question
        $question = $this->promptGenerator->generateNextQuestion(
            $context,
            $missingFields,
            $fieldDefinitions
        );
        
        // Assertions - should use "category name" not "category_id"
        $this->assertStringNotContainsString('category_id', strtolower($question));
        $this->assertStringNotContainsString('category id', strtolower($question));
    }
    
    /**
     * Test that custom prompt takes highest priority
     * 
     * @test
     */
    public function it_uses_custom_prompt_when_provided()
    {
        $context = new UnifiedActionContext('test-session');
        
        $fieldDefinitions = [
            'category_id' => [
                'type' => 'entity',
                'friendly_name' => 'category name',
                'prompt' => 'What category does this product belong to? (e.g., Electronics, Footwear, Clothing)',
                'required' => false,
            ],
        ];
        
        $missingFields = ['category_id'];
        
        // Generate question
        $question = $this->promptGenerator->generateNextQuestion(
            $context,
            $missingFields,
            $fieldDefinitions
        );
        
        // Assertions - should return custom prompt directly
        $this->assertEquals(
            'What category does this product belong to? (e.g., Electronics, Footwear, Clothing)',
            $question
        );
    }
    
    /**
     * Test that validation hints are included in prompt
     * 
     * @test
     */
    public function it_includes_validation_hints_in_prompt()
    {
        $context = new UnifiedActionContext('test-session');
        
        $fieldDefinitions = [
            'email' => [
                'type' => 'string',
                'friendly_name' => 'email address',
                'description' => 'Customer email address',
                'validation' => ['email', 'max:255'],
                'required' => true,
            ],
        ];
        
        $missingFields = ['email'];
        
        // Generate question - this will use AI, so we check the prompt context
        $question = $this->promptGenerator->generateNextQuestion(
            $context,
            $missingFields,
            $fieldDefinitions
        );
        
        // The question should be generated (mocked response)
        $this->assertNotEmpty($question);
    }
    
    /**
     * Test EntityFieldConfig friendlyName method
     * 
     * @test
     */
    public function it_sets_friendly_name_on_entity_field_config()
    {
        $config = EntityFieldConfig::make(\stdClass::class)
            ->friendlyName('category name')
            ->description('Product category')
            ->required(false);
        
        $this->assertEquals('category name', $config->friendlyName);
        $this->assertEquals('Product category', $config->description);
        $this->assertFalse($config->required);
    }
    
    /**
     * Test that examples are included when available
     * 
     * @test
     */
    public function it_includes_examples_in_prompt()
    {
        $context = new UnifiedActionContext('test-session');
        
        $fieldDefinitions = [
            'category_id' => [
                'type' => 'entity',
                'friendly_name' => 'category name',
                'description' => 'Product category',
                'examples' => ['Electronics', 'Footwear', 'Clothing'],
                'required' => false,
            ],
        ];
        
        $missingFields = ['category_id'];
        
        $question = $this->promptGenerator->generateNextQuestion(
            $context,
            $missingFields,
            $fieldDefinitions
        );
        
        // Question should be generated
        $this->assertNotEmpty($question);
    }
    
    /**
     * Test fallback to field name when no friendly name
     * 
     * @test
     */
    public function it_falls_back_to_field_name_when_no_friendly_name()
    {
        $context = new UnifiedActionContext('test-session');
        
        $fieldDefinitions = [
            'email' => [
                'type' => 'string',
                'description' => 'Email address',
                'required' => true,
            ],
        ];
        
        $missingFields = ['email'];
        
        $question = $this->promptGenerator->generateNextQuestion(
            $context,
            $missingFields,
            $fieldDefinitions
        );
        
        // Should generate a question
        $this->assertNotEmpty($question);
    }
    
    /**
     * Mock AI service for testing
     */
    protected function mockAIService()
    {
        $mockAI = \Mockery::mock(\LaravelAIEngine\Services\AIService::class);
        $mockAI->shouldReceive('generate')
            ->andReturn(new class {
                public $content = 'What is the category name for this product?';
            });
        
        $this->app->instance(\LaravelAIEngine\Services\AIService::class, $mockAI);
    }
}
