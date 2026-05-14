<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use LaravelAIEngine\Contracts\CreditLifecycleInterface;
use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\Models\DynamicModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class CreditlessOwner extends Model
{
    protected $table = 'creditless_owners';
    public $timestamps = false;
    protected $guarded = [];
}

class CreditlessOwnerResolver
{
    public function resolve(string $ownerId): Model
    {
        return CreditlessOwner::query()->findOrFail($ownerId);
    }
}

class ScalarCreditOwner extends Model
{
    protected $table = 'scalar_credit_owners';
    public $timestamps = false;
    protected $guarded = [];
}

class ScalarCreditOwnerResolver
{
    public function resolve(string $ownerId): Model
    {
        return ScalarCreditOwner::query()->findOrFail($ownerId);
    }
}

class LifecycleCreditOwner extends Model
{
    protected $table = 'lifecycle_credit_owners';
    public $timestamps = false;
    protected $guarded = [];
}

class LifecycleCreditOwnerResolver
{
    public function resolve(string $ownerId): Model
    {
        return LifecycleCreditOwner::query()->findOrFail($ownerId);
    }
}

class BudgetLifecycleHandler implements CreditLifecycleInterface
{
    public static float $deducted = 0.0;

    public function hasCredits(Model $owner, AIRequest $request): bool
    {
        return (float) $owner->credits_balance > 0;
    }

    public function deductCredits(Model $owner, AIRequest $request, float $creditsToDeduct): bool
    {
        self::$deducted += $creditsToDeduct;
        $owner->credits_balance = (float) $owner->credits_balance - $creditsToDeduct;

        return $owner->save();
    }

    public function addCredits(Model $owner, float $credits, array $metadata = []): bool
    {
        $owner->credits_balance = (float) $owner->credits_balance + $credits;

        return $owner->save();
    }

    public function getAvailableCredits(Model $owner): float
    {
        return (float) $owner->credits_balance;
    }

    public function hasLowCredits(Model $owner): bool
    {
        return (float) $owner->credits_balance < 10.0;
    }
}

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
        
        // Includes engine conversion rate in addition to model credit index.
        $this->assertEquals(40.0, $credits);
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
        
        // Includes engine conversion rate in addition to model credit index.
        $this->assertEquals(20.0, $credits);
    }

    public function test_calculate_credits_for_fal_image_generation_uses_provider_count_aliases(): void
    {
        Config::set('ai-engine.credits.engine_rates.fal_ai', 1.25);

        $frameCountRequest = new AIRequest(
            prompt: 'Generate product keyframes',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_NANO_BANANA_2,
            parameters: ['frame_count' => 3],
            userId: $this->testUser->id
        );

        $numImagesRequest = new AIRequest(
            prompt: 'Generate product variations',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_NANO_BANANA_2,
            parameters: ['num_images' => 2],
            userId: $this->testUser->id
        );

        $this->assertEqualsWithDelta(14.25, $this->creditManager->calculateCredits($frameCountRequest), 0.0001);
        $this->assertEqualsWithDelta(9.5, $this->creditManager->calculateCredits($numImagesRequest), 0.0001);
    }

    public function test_calculate_credits_for_dynamic_fal_vision_model_uses_image_units(): void
    {
        Config::set('ai-engine.credits.engine_rates.fal_ai', 1.5);

        AIModel::query()->create([
            'provider' => 'fal_ai',
            'model_id' => 'fal-ai/test/vision-caption',
            'name' => 'FAL Vision Caption',
            'capabilities' => ['vision', 'image_analysis'],
            'supports_vision' => true,
            'supports_streaming' => false,
            'supports_function_calling' => false,
            'supports_json_mode' => false,
            'is_active' => true,
            'is_deprecated' => false,
        ]);
        app(DynamicModelResolver::class)->clearCache('fal-ai/test/vision-caption');

        $request = new AIRequest(
            prompt: 'describe this uploaded image carefully',
            engine: EngineEnum::FAL_AI,
            model: 'fal-ai/test/vision-caption',
            parameters: ['image_url' => 'https://example.com/image.png'],
            userId: $this->testUser->id
        );

        $this->assertSame('image', $request->getModel()->contentType());
        $this->assertEqualsWithDelta(1.5, $this->creditManager->calculateCredits($request), 0.0001);
    }

    public function test_calculate_credits_can_include_fal_reference_input_images_by_model_policy(): void
    {
        Config::set('ai-engine.credits.engine_rates.fal_ai', 1.0);
        Config::set('ai-engine.credits.additional_input_unit_rates.fal_ai.models', [
            EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO => [
                'image' => 0.5,
            ],
        ]);

        $request = new AIRequest(
            prompt: 'Animate this product from references',
            engine: EngineEnum::FAL_AI,
            model: EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO,
            parameters: [
                'image_url' => 'https://example.com/hero.png',
                'reference_image_urls' => [
                    'https://example.com/ref-1.png',
                    'https://example.com/ref-2.png',
                ],
            ],
            userId: $this->testUser->id
        );

        $this->assertEqualsWithDelta(9.5, $this->creditManager->calculateCredits($request), 0.0001);
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
            $creditsToAdd,
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
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
            $newBalance,
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
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
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
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

    public function test_run_budget_tracks_response_usage_and_remaining_limits(): void
    {
        $run = app(\LaravelAIEngine\Repositories\AgentRunRepository::class)->create([
            'session_id' => 'credit-manager-budget',
            'user_id' => (string) $this->testUser->id,
            'status' => \LaravelAIEngine\Models\AIAgentRun::STATUS_RUNNING,
            'metadata' => [],
        ]);

        $this->creditManager->startRunBudget($run, $this->testUser->id, [
            'max_tokens' => 100,
            'max_cost' => 5.0,
            'max_credits' => 10.0,
            'engine' => EngineEnum::OPENAI,
            'model' => EntityEnum::GPT_4O,
        ]);

        $response = new AIResponse(
            content: 'ok',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            tokensUsed: 60,
            creditsUsed: 2.5,
            usage: ['total_cost' => 1.25]
        );

        $updated = $this->creditManager->recordRunUsage($run->uuid, $response);
        $remaining = $this->creditManager->remainingRunBudget($updated);

        $this->assertSame(60, $updated->metadata['tokens_used']);
        $this->assertSame(1.25, $updated->metadata['cost_used']);
        $this->assertSame(2.5, $updated->metadata['credits_used']);
        $this->assertSame('gpt-4o', $updated->metadata['provider_model']);
        $this->assertSame(40, $remaining['remaining']['tokens']);
        $this->assertSame(3.75, $remaining['remaining']['cost']);
        $this->assertSame(7.5, $remaining['remaining']['credits']);
        $this->assertTrue($this->creditManager->assertRunBudgetAvailable($updated));

        $updated = $this->creditManager->recordRunUsage($updated, ['total_tokens' => 50]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent run exceeded token budget [100].');
        $this->creditManager->assertRunBudgetAvailable($updated);
    }

    public function test_run_budget_can_deduct_scalar_owner_credits(): void
    {
        $this->createScalarCreditOwnerTable();
        $owner = ScalarCreditOwner::query()->create([
            'name' => 'Scalar Owner',
            'my_credits' => 12.0,
            'has_unlimited_credits' => false,
        ]);
        config()->set('ai-engine.credits.owner_model', ScalarCreditOwner::class);
        config()->set('ai-engine.credits.query_resolver', ScalarCreditOwnerResolver::class);

        $run = app(\LaravelAIEngine\Repositories\AgentRunRepository::class)->create([
            'session_id' => 'scalar-budget',
            'status' => \LaravelAIEngine\Models\AIAgentRun::STATUS_RUNNING,
            'metadata' => [],
        ]);

        $this->creditManager->startRunBudget($run, $owner->id, [
            'max_credits' => 10.0,
            'deduct_credits' => true,
        ]);
        $updated = $this->creditManager->recordRunUsage($run, ['credits_used' => 3.5]);
        $remaining = $this->creditManager->remainingRunBudget($updated);

        $this->assertSame(8.5, (float) $owner->fresh()->my_credits);
        $this->assertSame(6.5, $remaining['remaining']['credits']);
    }

    public function test_run_budget_can_deduct_entity_ledger_credits(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 10.0, 'is_unlimited' => false],
                ],
            ],
        ]);
        $run = app(\LaravelAIEngine\Repositories\AgentRunRepository::class)->create([
            'session_id' => 'entity-budget',
            'user_id' => (string) $user->id,
            'status' => \LaravelAIEngine\Models\AIAgentRun::STATUS_RUNNING,
            'metadata' => [],
        ]);

        $this->creditManager->startRunBudget($run, $user->id, [
            'max_credits' => 10.0,
            'engine' => EngineEnum::OPENAI,
            'model' => EntityEnum::GPT_4O,
            'deduct_credits' => true,
        ]);
        $updated = $this->creditManager->recordRunUsage($run, ['credits_used' => 4.0]);
        $remaining = $this->creditManager->remainingRunBudget($updated);

        $this->assertSame(6.0, (float) data_get($user->fresh()->entity_credits, 'openai.gpt-4o.balance'));
        $this->assertSame(6.0, $remaining['remaining']['credits']);
    }

    public function test_run_budget_reuses_custom_lifecycle_handler(): void
    {
        $this->createLifecycleCreditOwnerTable();
        BudgetLifecycleHandler::$deducted = 0.0;
        $owner = LifecycleCreditOwner::query()->create([
            'name' => 'Lifecycle Owner',
            'credits_balance' => 20.0,
        ]);
        config()->set('ai-engine.credits.owner_model', LifecycleCreditOwner::class);
        config()->set('ai-engine.credits.query_resolver', LifecycleCreditOwnerResolver::class);
        config()->set('ai-engine.credits.lifecycle_handler', BudgetLifecycleHandler::class);

        $run = app(\LaravelAIEngine\Repositories\AgentRunRepository::class)->create([
            'session_id' => 'lifecycle-budget',
            'status' => \LaravelAIEngine\Models\AIAgentRun::STATUS_RUNNING,
            'metadata' => [],
        ]);

        $this->creditManager->startRunBudget($run, $owner->id, [
            'max_credits' => 15.0,
            'deduct_credits' => true,
        ]);
        $updated = $this->creditManager->recordRunUsage($run, ['credits_used' => 5.0]);
        $remaining = $this->creditManager->remainingRunBudget($updated);

        $this->assertSame(5.0, BudgetLifecycleHandler::$deducted);
        $this->assertSame(15.0, (float) $owner->fresh()->credits_balance);
        $this->assertSame(10.0, $remaining['remaining']['credits']);
    }

    public function test_reset_credits()
    {
        // Modify user credits first
        $this->creditManager->setCredits(
            $this->testUser->id,
            500.0,
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
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

    public function test_credit_checks_noop_when_owner_model_has_no_credit_storage(): void
    {
        if (!Schema::hasTable('creditless_owners')) {
            Schema::create('creditless_owners', function ($table) {
                $table->id();
                $table->string('name');
            });
        }

        $owner = CreditlessOwner::query()->create(['name' => 'No Credit Columns']);

        config()->set('ai-engine.credits.owner_model', CreditlessOwner::class);
        config()->set('ai-engine.credits.query_resolver', CreditlessOwnerResolver::class);

        $request = new AIRequest(
            prompt: 'Use the real provider without credit columns.',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O_MINI,
            userId: (string) $owner->id
        );

        $this->assertTrue($this->creditManager->hasCredits((string) $owner->id, $request));
        $this->assertTrue($this->creditManager->deductCredits((string) $owner->id, $request));
        $this->assertSame(['id', 'name'], array_keys($owner->fresh()->getAttributes()));
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

    private function createScalarCreditOwnerTable(): void
    {
        if (Schema::hasTable('scalar_credit_owners')) {
            return;
        }

        Schema::create('scalar_credit_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->float('my_credits')->default(0);
            $table->boolean('has_unlimited_credits')->default(false);
        });
    }

    private function createLifecycleCreditOwnerTable(): void
    {
        if (Schema::hasTable('lifecycle_credit_owners')) {
            return;
        }

        Schema::create('lifecycle_credit_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->float('credits_balance')->default(0);
        });
    }
}
