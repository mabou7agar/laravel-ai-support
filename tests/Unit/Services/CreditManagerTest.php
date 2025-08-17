<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Services;

use MagicAI\LaravelAIEngine\Tests\TestCase;
use MagicAI\LaravelAIEngine\Services\CreditManager;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\Exceptions\InsufficientCreditsException;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreditManagerTest extends TestCase
{
    use RefreshDatabase;

    private CreditManager $creditManager;
    private $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creditManager = app(CreditManager::class);
        $this->testUser = $this->createTestUser();
    }

    public function test_calculate_credits_for_text_generation()
    {
        $request = new AIRequest(
            prompt: 'This is a test prompt with ten words exactly here.',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $this->testUser->id
        );

        $credits = $this->creditManager->calculateCredits($request);
        
        // Should be word count (10) * credit index (2.0 for GPT-4O)
        $this->assertEquals(20.0, $credits);
    }

    public function test_calculate_credits_for_image_generation()
    {
        $request = new AIRequest(
            prompt: 'Generate an image',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::DALL_E_3,
            parameters: ['image_count' => 2],
            userId: $this->testUser->id
        );

        $credits = $this->creditManager->calculateCredits($request);
        
        // Should be image count (2) * credit index (5.0 for DALL-E 3)
        $this->assertEquals(10.0, $credits);
    }

    public function test_has_credits_with_sufficient_balance()
    {
        $request = new AIRequest(
            prompt: 'Short prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $this->testUser->id
        );

        $this->assertTrue($this->creditManager->hasCredits($this->testUser->id, $request));
    }

    public function test_has_credits_with_insufficient_balance()
    {
        // Create user with low credits
        $lowCreditUser = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 1.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $request = new AIRequest(
            prompt: 'This is a test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $lowCreditUser->id
        );
        
        // Create a custom credit manager that always returns 2.0 credits (more than available)
        $creditManager = new class($this->app) extends CreditManager {
            public function calculateCredits(AIRequest $request): float
            {
                return 2.0; // Always return more than the 1.0 balance
            }
        };

        $this->assertFalse($creditManager->hasCredits($lowCreditUser->id, $request));
    }

    public function test_has_credits_with_unlimited_plan()
    {
        $unlimitedUser = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 0, 'is_unlimited' => true],
                ],
            ],
        ]);

        $request = new AIRequest(
            prompt: 'Very long prompt that would normally require many credits',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $unlimitedUser->id
        );

        $this->assertTrue($this->creditManager->hasCredits($unlimitedUser->id, $request));
    }

    public function test_deduct_credits_successfully()
    {
        $initialBalance = 100.0;
        $request = new AIRequest(
            prompt: 'Five word test prompt here',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $this->testUser->id
        );

        $creditsToDeduct = $this->creditManager->calculateCredits($request);
        $result = $this->creditManager->deductCredits($this->testUser->id, $request);

        $this->assertTrue($result);
        
        $remainingCredits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );
        
        $this->assertEquals($initialBalance - $creditsToDeduct, $remainingCredits['balance']);
    }

    public function test_deduct_credits_throws_exception_for_insufficient_balance()
    {
        $lowCreditUser = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 1.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $request = new AIRequest(
            prompt: 'This is a very long prompt that will require more credits than available',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $lowCreditUser->id
        );

        $this->expectException(InsufficientCreditsException::class);
        $this->creditManager->deductCredits($lowCreditUser->id, $request);
    }

    public function test_add_credits()
    {
        $initialCredits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $creditsToAdd = 50.0;
        $result = $this->creditManager->addCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            $creditsToAdd
        );

        $this->assertTrue($result);

        $newCredits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->assertEquals($initialCredits['balance'] + $creditsToAdd, $newCredits['balance']);
    }

    public function test_set_credits()
    {
        $newBalance = 200.0;
        $result = $this->creditManager->setCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            $newBalance
        );

        $this->assertTrue($result);

        $credits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->assertEquals($newBalance, $credits['balance']);
        $this->assertFalse($credits['is_unlimited']);
    }

    public function test_set_unlimited_credits()
    {
        $result = $this->creditManager->setUnlimitedCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->assertTrue($result);

        $credits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->assertTrue($credits['is_unlimited']);
    }

    public function test_get_total_credits()
    {
        $totalCredits = $this->creditManager->getTotalCredits($this->testUser->id);
        
        // Should sum up all credits across engines and models
        $this->assertGreaterThan(0, $totalCredits);
        $this->assertEquals(225.0, $totalCredits); // 100 + 50 + 75 from test user setup
    }

    public function test_get_total_credits_with_unlimited()
    {
        $unlimitedUser = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 0, 'is_unlimited' => true],
                ],
            ],
        ]);

        $totalCredits = $this->creditManager->getTotalCredits($unlimitedUser->id);
        
        $this->assertEquals(PHP_FLOAT_MAX, $totalCredits);
    }

    public function test_has_low_credits()
    {
        $lowCreditUser = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 5.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $this->assertTrue($this->creditManager->hasLowCredits($lowCreditUser->id));
        $this->assertFalse($this->creditManager->hasLowCredits($this->testUser->id));
    }

    public function test_get_usage_stats()
    {
        $stats = $this->creditManager->getUsageStats($this->testUser->id);
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('total_credits_used', $stats);
        $this->assertArrayHasKey('average_credits_per_request', $stats);
        $this->assertArrayHasKey('most_used_engine', $stats);
        $this->assertArrayHasKey('most_used_model', $stats);
        $this->assertArrayHasKey('period', $stats);
    }

    public function test_reset_credits()
    {
        // Modify user credits first
        $this->creditManager->setCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            500.0
        );

        // Reset credits
        $result = $this->creditManager->resetCredits($this->testUser->id);
        $this->assertTrue($result);

        // Verify credits are reset to defaults
        $credits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->assertEquals(100.0, $credits['balance']); // Default balance from config
        $this->assertFalse($credits['is_unlimited']);
    }

    public function test_get_user_credits_for_new_model()
    {
        // Request credits for a model not in user's entity_credits
        $credits = $this->creditManager->getUserCredits(
            $this->testUser->id,
            EngineEnum::GEMINI,
            EntityEnum::GEMINI_1_5_PRO
        );

        $this->assertEquals(100.0, $credits['balance']); // Default balance
        $this->assertFalse($credits['is_unlimited']);
    }
}
