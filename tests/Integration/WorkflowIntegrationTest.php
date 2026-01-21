<?php

namespace LaravelAIEngine\Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * Integration Test for Workflow Features
 * 
 * This test simulates real API requests similar to the curl commands
 * used during manual testing. It tests the complete workflow including:
 * - Price display in confirmation messages
 * - FriendlyName in category prompts
 * - AI-driven validation
 * - Entity resolution with subflows
 * 
 * Run with: php artisan test tests/Integration/WorkflowIntegrationTest.php
 */
class WorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase, WithFaker;
    
    protected string $baseUrl = '/api/ai-chat/send';
    protected string $sessionId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionId = 'test-integration-' . uniqid();
    }
    
    /**
     * Test complete invoice creation workflow with price display
     * 
     * @test
     */
    public function it_creates_invoice_with_correct_price_display()
    {
        // Step 1: Create invoice with new customer and product
        $response = $this->postJson($this->baseUrl, [
            'message' => 'create invoice for John Smith with 2 Laptops',
            'session_id' => $this->sessionId,
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'response',
            'session_id',
            'metadata',
        ]);
        
        // Should ask to create customer
        $this->assertStringContainsString("John Smith", $response->json('response'));
        $this->assertStringContainsString("create", strtolower($response->json('response')));
        
        // Step 2: Confirm customer creation
        $response = $this->postJson($this->baseUrl, [
            'message' => 'yes',
            'session_id' => $this->sessionId,
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Should ask for email
        $this->assertStringContainsString("email", strtolower($response->json('response')));
        
        // Step 3: Provide email
        $response = $this->postJson($this->baseUrl, [
            'message' => 'john@example.com',
            'session_id' => $this->sessionId,
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Customer should be created, now asking about product
        // May ask to create product or confirm
        $responseText = strtolower($response->json('response'));
        
        // Continue workflow until completion
        $maxSteps = 10;
        $step = 0;
        
        while ($step < $maxSteps) {
            $step++;
            
            $responseText = strtolower($response->json('response'));
            
            // Check if workflow completed
            if (str_contains($responseText, 'invoice') && 
                (str_contains($responseText, 'created') || str_contains($responseText, 'success'))) {
                
                // Verify price display
                $finalResponse = $response->json('response');
                
                // Should NOT contain $0
                $this->assertStringNotContainsString('$0', $finalResponse);
                
                // Should contain actual prices
                $this->assertMatchesRegularExpression('/\$\d+/', $finalResponse);
                
                // Should contain product name
                $this->assertStringContainsString('Laptop', $finalResponse);
                
                // Should contain quantity
                $this->assertMatchesRegularExpression('/[×x]\s*2/', $finalResponse);
                
                break;
            }
            
            // Handle different prompts
            if (str_contains($responseText, 'create') && str_contains($responseText, '?')) {
                $message = 'yes';
            } elseif (str_contains($responseText, 'email')) {
                $message = 'test@example.com';
            } elseif (str_contains($responseText, 'phone') || str_contains($responseText, 'contact')) {
                $message = 'skip';
            } elseif (str_contains($responseText, 'address')) {
                $message = 'skip';
            } elseif (str_contains($responseText, 'price')) {
                $message = '999.99';
            } elseif (str_contains($responseText, 'category')) {
                $message = 'Electronics';
            } elseif (str_contains($responseText, 'confirm')) {
                $message = 'yes';
            } else {
                $message = 'continue';
            }
            
            $response = $this->postJson($this->baseUrl, [
                'message' => $message,
                'session_id' => $this->sessionId,
                'memory' => true,
                'actions' => true,
            ]);
            
            $response->assertStatus(200);
        }
        
        $this->assertLessThan($maxSteps, $step, 'Workflow did not complete in expected steps');
    }
    
    /**
     * Test category prompt uses friendly name instead of ID
     * 
     * @test
     */
    public function it_asks_for_category_name_not_id()
    {
        // Create invoice with product that needs category
        $response = $this->postJson($this->baseUrl, [
            'message' => 'create invoice for Jane Doe with 1 Soccer Ball',
            'session_id' => $this->sessionId . '-category',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Navigate through workflow until category question
        $maxSteps = 15;
        $foundCategoryPrompt = false;
        
        for ($step = 0; $step < $maxSteps; $step++) {
            $responseText = $response->json('response');
            $lowerResponse = strtolower($responseText);
            
            // Check if asking for category
            if (str_contains($lowerResponse, 'category')) {
                // Should NOT ask for "category ID"
                $this->assertStringNotContainsString('category id', $lowerResponse);
                $this->assertStringNotContainsString('category_id', $lowerResponse);
                
                // Should ask for category name or just "category"
                $foundCategoryPrompt = true;
                break;
            }
            
            // Auto-respond to move forward
            if (str_contains($lowerResponse, 'create') && str_contains($lowerResponse, '?')) {
                $message = 'yes';
            } elseif (str_contains($lowerResponse, 'email')) {
                $message = 'jane@example.com';
            } elseif (str_contains($lowerResponse, 'phone') || str_contains($lowerResponse, 'contact')) {
                $message = 'skip';
            } elseif (str_contains($lowerResponse, 'price')) {
                $message = '29.99';
            } else {
                $message = 'yes';
            }
            
            $response = $this->postJson($this->baseUrl, [
                'message' => $message,
                'session_id' => $this->sessionId . '-category',
                'memory' => true,
                'actions' => true,
            ]);
            
            $response->assertStatus(200);
        }
        
        $this->assertTrue($foundCategoryPrompt, 'Category prompt was not found in workflow');
    }
    
    /**
     * Test AI handles email validation naturally
     * 
     * @test
     */
    public function it_handles_email_validation_naturally()
    {
        // Start invoice creation
        $response = $this->postJson($this->baseUrl, [
            'message' => 'create invoice for Bob Wilson with 1 Mouse',
            'session_id' => $this->sessionId . '-validation',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Confirm customer creation
        $response = $this->postJson($this->baseUrl, [
            'message' => 'yes',
            'session_id' => $this->sessionId . '-validation',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Should ask for email
        $this->assertStringContainsString('email', strtolower($response->json('response')));
        
        // Provide invalid email
        $response = $this->postJson($this->baseUrl, [
            'message' => 'bob@invalid',
            'session_id' => $this->sessionId . '-validation',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // AI should handle validation (may ask for clarification or accept and validate later)
        // The key is that it doesn't throw a hard error
        $this->assertTrue($response->json('success'));
        
        // Provide valid email
        $response = $this->postJson($this->baseUrl, [
            'message' => 'bob@example.com',
            'session_id' => $this->sessionId . '-validation',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
    }
    
    /**
     * Test existing product includes prices
     * 
     * @test
     */
    public function it_includes_prices_for_existing_products()
    {
        // First, create a product
        $this->createTestProduct('Test Laptop', 1299.99);
        
        // Create invoice with existing product
        $response = $this->postJson($this->baseUrl, [
            'message' => 'create invoice for Alice Brown with 1 Test Laptop',
            'session_id' => $this->sessionId . '-existing',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Navigate through workflow
        $maxSteps = 15;
        
        for ($step = 0; $step < $maxSteps; $step++) {
            $responseText = $response->json('response');
            $lowerResponse = strtolower($responseText);
            
            // Check if invoice created
            if (str_contains($lowerResponse, 'invoice') && 
                (str_contains($lowerResponse, 'created') || str_contains($lowerResponse, 'success'))) {
                
                // Should show price for existing product
                $this->assertStringNotContainsString('$0', $responseText);
                $this->assertStringContainsString('1299.99', $responseText);
                break;
            }
            
            // Auto-respond
            if (str_contains($lowerResponse, 'create') && str_contains($lowerResponse, '?')) {
                $message = 'yes';
            } elseif (str_contains($lowerResponse, 'email')) {
                $message = 'alice@example.com';
            } elseif (str_contains($lowerResponse, 'confirm')) {
                $message = 'yes';
            } else {
                $message = 'continue';
            }
            
            $response = $this->postJson($this->baseUrl, [
                'message' => $message,
                'session_id' => $this->sessionId . '-existing',
                'memory' => true,
                'actions' => true,
            ]);
            
            $response->assertStatus(200);
        }
    }
    
    /**
     * Test multiple products with different prices
     * 
     * @test
     */
    public function it_displays_multiple_products_with_correct_prices()
    {
        $response = $this->postJson($this->baseUrl, [
            'message' => 'create invoice for Mike Johnson with 2 Laptops and 3 Mice',
            'session_id' => $this->sessionId . '-multiple',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        
        // Navigate through workflow
        $maxSteps = 20;
        
        for ($step = 0; $step < $maxSteps; $step++) {
            $responseText = $response->json('response');
            $lowerResponse = strtolower($responseText);
            
            // Check if invoice created
            if (str_contains($lowerResponse, 'invoice') && 
                (str_contains($lowerResponse, 'created') || str_contains($lowerResponse, 'success'))) {
                
                // Should show both products with prices
                $this->assertStringContainsString('Laptop', $responseText);
                $this->assertStringContainsString('Mice', $responseText);
                
                // Should show quantities
                $this->assertMatchesRegularExpression('/[×x]\s*2/', $responseText);
                $this->assertMatchesRegularExpression('/[×x]\s*3/', $responseText);
                
                // Should NOT have $0
                $this->assertStringNotContainsString('$0', $responseText);
                
                // Should have total
                $this->assertStringContainsString('Total', $responseText);
                
                break;
            }
            
            // Auto-respond
            if (str_contains($lowerResponse, 'create') && str_contains($lowerResponse, '?')) {
                $message = 'yes';
            } elseif (str_contains($lowerResponse, 'email')) {
                $message = 'mike@example.com';
            } elseif (str_contains($lowerResponse, 'phone') || str_contains($lowerResponse, 'contact')) {
                $message = 'skip';
            } elseif (str_contains($lowerResponse, 'price')) {
                // Provide price for product being asked
                if (str_contains($lowerResponse, 'laptop')) {
                    $message = '999.99';
                } else {
                    $message = '29.99';
                }
            } elseif (str_contains($lowerResponse, 'category')) {
                $message = 'Electronics';
            } elseif (str_contains($lowerResponse, 'confirm')) {
                $message = 'yes';
            } else {
                $message = 'continue';
            }
            
            $response = $this->postJson($this->baseUrl, [
                'message' => $message,
                'session_id' => $this->sessionId . '-multiple',
                'memory' => true,
                'actions' => true,
            ]);
            
            $response->assertStatus(200);
        }
    }
    
    /**
     * Test workflow completion without errors
     * 
     * @test
     */
    public function it_completes_workflow_without_errors()
    {
        $response = $this->postJson($this->baseUrl, [
            'message' => 'create invoice for Sarah Davis with 1 Keyboard',
            'session_id' => $this->sessionId . '-complete',
            'memory' => true,
            'actions' => true,
        ]);
        
        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        // Navigate through entire workflow
        $maxSteps = 15;
        $completed = false;
        
        for ($step = 0; $step < $maxSteps; $step++) {
            $responseText = $response->json('response');
            $lowerResponse = strtolower($responseText);
            
            // Check for completion
            if (str_contains($lowerResponse, 'invoice') && 
                (str_contains($lowerResponse, 'created') || str_contains($lowerResponse, 'success'))) {
                $completed = true;
                break;
            }
            
            // Check for errors
            $this->assertStringNotContainsString('error', $lowerResponse);
            $this->assertStringNotContainsString('failed', $lowerResponse);
            
            // Auto-respond
            if (str_contains($lowerResponse, 'create') && str_contains($lowerResponse, '?')) {
                $message = 'yes';
            } elseif (str_contains($lowerResponse, 'email')) {
                $message = 'sarah@example.com';
            } elseif (str_contains($lowerResponse, 'phone') || str_contains($lowerResponse, 'contact')) {
                $message = 'skip';
            } elseif (str_contains($lowerResponse, 'price')) {
                $message = '79.99';
            } elseif (str_contains($lowerResponse, 'category')) {
                $message = 'Electronics';
            } elseif (str_contains($lowerResponse, 'confirm')) {
                $message = 'yes';
            } else {
                $message = 'continue';
            }
            
            $response = $this->postJson($this->baseUrl, [
                'message' => $message,
                'session_id' => $this->sessionId . '-complete',
                'memory' => true,
                'actions' => true,
            ]);
            
            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
        }
        
        $this->assertTrue($completed, 'Workflow did not complete successfully');
    }
    
    /**
     * Helper: Create test product
     */
    protected function createTestProduct(string $name, float $price)
    {
        // This would use your actual Product model
        // Adjust based on your application structure
        if (class_exists(\Workdo\ProductService\Entities\ProductService::class)) {
            \Workdo\ProductService\Entities\ProductService::create([
                'name' => $name,
                'sale_price' => $price,
                'purchase_price' => $price * 0.5,
                'workspace_id' => 1,
                'created_by' => 1,
            ]);
        }
    }
}
