<?php

namespace LaravelAIEngine\Tests\Feature\Actions;

use LaravelAIEngine\Tests\Support\ActionTestCase;
use LaravelAIEngine\Tests\Support\ActionFactory;

/**
 * Example: Parameter Extraction Tests
 */
class ParameterExtractionTest extends ActionTestCase
{
    /**
     * Test complete parameter extraction
     */
    public function test_extracts_all_required_parameters()
    {
        // Arrange
        $actionDefinition = ActionFactory::actionDefinition([
            'required_params' => ['name', 'price'],
            'optional_params' => ['description'],
        ]);
        
        // Act
        $result = $this->actionExtractor->extract(
            message: "Create a product called iPhone 15 for $999",
            actionDefinition: $actionDefinition,
            context: []
        );
        
        // Assert
        $this->assertExtractionComplete($result);
        $this->assertParametersExtracted([
            'name' => 'iPhone 15',
            'price' => 999,
        ], $result->params);
        $this->assertHighConfidence($result);
    }
    
    /**
     * Test incomplete parameter extraction
     */
    public function test_identifies_missing_parameters()
    {
        // Arrange
        $actionDefinition = ActionFactory::actionDefinition([
            'required_params' => ['name', 'price', 'category'],
        ]);
        
        // Act
        $result = $this->actionExtractor->extract(
            message: "Create a product called iPhone 15",
            actionDefinition: $actionDefinition,
            context: []
        );
        
        // Assert
        $this->assertExtractionIncomplete($result, ['price', 'category']);
        $this->assertArrayHasKey('name', $result->params);
    }
    
    /**
     * Test extraction with conversation context
     */
    public function test_uses_conversation_context_for_extraction()
    {
        // Arrange
        $actionDefinition = ActionFactory::actionDefinition([
            'required_params' => ['name', 'price'],
        ]);
        
        $context = [
            'conversation_history' => [
                ['role' => 'user', 'content' => 'I want to add a new product'],
                ['role' => 'assistant', 'content' => 'What product would you like to add?'],
                ['role' => 'user', 'content' => 'An iPhone'],
                ['role' => 'assistant', 'content' => 'What price?'],
            ],
        ];
        
        // Act
        $result = $this->actionExtractor->extract(
            message: "$999",
            actionDefinition: $actionDefinition,
            context: $context
        );
        
        // Assert: Should extract both name from context and price from message
        $this->assertArrayHasKey('price', $result->params);
        $this->assertEquals(999, $result->params['price']);
    }
    
    /**
     * Test mocking parameter extraction
     */
    public function test_mock_parameter_extraction()
    {
        // Arrange: Mock extraction to return specific params
        $this->mockParameterExtraction(
            params: ['name' => 'Test Product', 'price' => 100],
            missing: [],
            confidence: 1.0
        );
        
        // Act
        $result = $this->actionExtractor->extract(
            message: "any message",
            actionDefinition: [],
            context: []
        );
        
        // Assert
        $this->assertExtractionComplete($result);
        $this->assertEquals('Test Product', $result->params['name']);
        $this->assertEquals(100, $result->params['price']);
    }
    
    /**
     * Test extraction confidence scoring
     */
    public function test_calculates_extraction_confidence()
    {
        // Test high confidence (all fields extracted)
        $highConfidence = ActionFactory::completeExtraction([
            'name' => 'Product',
            'price' => 100,
            'description' => 'Test',
        ]);
        $this->assertHighConfidence($highConfidence, threshold: 0.9);
        
        // Test low confidence (few fields extracted)
        $lowConfidence = ActionFactory::lowConfidenceExtraction([
            'name' => 'Product',
        ]);
        $this->assertLessThan(0.7, $lowConfidence->confidence);
    }
}
