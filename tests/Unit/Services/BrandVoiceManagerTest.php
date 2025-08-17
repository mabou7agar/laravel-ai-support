<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Services\BrandVoiceManager;
use Illuminate\Support\Facades\Storage;

class BrandVoiceManagerTest extends TestCase
{
    private BrandVoiceManager $brandVoiceManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brandVoiceManager = app(BrandVoiceManager::class);
        Storage::fake('local');
    }

    public function test_create_brand_voice()
    {
        $user = $this->createTestUser();
        $brandVoiceData = [
            'name' => 'Professional Tech Company',
            'description' => 'A professional technology company voice',
            'tone' => 'professional',
            'style' => 'informative',
            'target_audience' => 'developers',
            'key_messages' => ['innovation', 'reliability', 'expertise'],
            'avoid_words' => ['cheap', 'basic'],
            'sample_content' => 'We provide cutting-edge solutions for modern developers.',
        ];

        $brandVoice = $this->brandVoiceManager->createBrandVoice($user->id, $brandVoiceData);

        $this->assertIsArray($brandVoice);
        $this->assertEquals('Professional Tech Company', $brandVoice['name']);
        $this->assertEquals('professional', $brandVoice['tone']);
        $this->assertArrayHasKey('id', $brandVoice);
        $this->assertArrayHasKey('user_id', $brandVoice);
        $this->assertEquals($user->id, $brandVoice['user_id']);
        $this->assertArrayHasKey('created_at', $brandVoice);
    }

    public function test_get_brand_voice()
    {
        // Create a brand voice first
        $brandVoiceData = [
            'name' => 'Test Brand',
            'tone' => 'casual',
            'style' => 'friendly',
        ];

        $created = $this->brandVoiceManager->createBrandVoice('user-123', $brandVoiceData);
        $brandVoiceId = $created['id'];

        // Retrieve it
        $retrieved = $this->brandVoiceManager->getBrandVoice('user-123', $brandVoiceId);

        $this->assertIsArray($retrieved);
        $this->assertEquals('Test Brand', $retrieved['name']);
        $this->assertEquals('casual', $retrieved['tone']);
        $this->assertEquals($brandVoiceId, $retrieved['id']);
    }

    public function test_get_nonexistent_brand_voice_returns_null()
    {
        $result = $this->brandVoiceManager->getBrandVoice('user-123', 'nonexistent-id');
        $this->assertNull($result);
    }

    public function test_update_brand_voice()
    {
        // Create a brand voice first
        $brandVoiceData = [
            'name' => 'Original Name',
            'tone' => 'professional',
        ];

        $created = $this->brandVoiceManager->createBrandVoice('user-123', $brandVoiceData);
        $brandVoiceId = $created['id'];

        // Update it
        $updateData = [
            'name' => 'Updated Name',
            'tone' => 'casual',
            'style' => 'friendly',
        ];

        $updated = $this->brandVoiceManager->updateBrandVoice('user-123', $brandVoiceId, $updateData);

        $this->assertTrue($updated);

        // Verify the update
        $retrieved = $this->brandVoiceManager->getBrandVoice('user-123', $brandVoiceId);
        $this->assertEquals('Updated Name', $retrieved['name']);
        $this->assertEquals('casual', $retrieved['tone']);
        $this->assertEquals('friendly', $retrieved['style']);
    }

    public function test_delete_brand_voice()
    {
        // Create a brand voice first
        $brandVoiceData = ['name' => 'To Delete', 'tone' => 'professional'];
        $created = $this->brandVoiceManager->createBrandVoice('user-123', $brandVoiceData);
        $brandVoiceId = $created['id'];

        // Delete it
        $deleted = $this->brandVoiceManager->deleteBrandVoice('user-123', $brandVoiceId);
        $this->assertTrue($deleted);

        // Verify it's gone
        $retrieved = $this->brandVoiceManager->getBrandVoice('user-123', $brandVoiceId);
        $this->assertNull($retrieved);
    }

    public function test_list_user_brand_voices()
    {
        // Create multiple brand voices
        $this->brandVoiceManager->createBrandVoice('user-123', ['name' => 'Voice 1', 'tone' => 'professional']);
        $this->brandVoiceManager->createBrandVoice('user-123', ['name' => 'Voice 2', 'tone' => 'casual']);
        $this->brandVoiceManager->createBrandVoice('user-456', ['name' => 'Voice 3', 'tone' => 'formal']);

        $userVoices = $this->brandVoiceManager->getUserBrandVoices('user-123');

        $this->assertIsArray($userVoices);
        $this->assertCount(2, $userVoices);
        
        $names = array_column($userVoices, 'name');
        $this->assertContains('Voice 1', $names);
        $this->assertContains('Voice 2', $names);
        $this->assertNotContains('Voice 3', $names);
    }

    public function test_apply_brand_voice_to_prompt()
    {
        // Create a brand voice
        $brandVoiceData = [
            'name' => 'Developer Brand',
            'tone' => 'professional',
            'target_audience' => 'developers',
        ];

        $created = $this->brandVoiceManager->createBrandVoice('user-123', $brandVoiceData);
        $brandVoiceId = $created['id'];

        $originalPrompt = 'Write a blog post about our new product.';
        $enhancedPrompt = $this->brandVoiceManager->applyBrandVoiceToPrompt(
            'user-123',
            $brandVoiceId,
            $originalPrompt
        );

        $this->assertIsString($enhancedPrompt);
        $this->assertNotEquals($originalPrompt, $enhancedPrompt);
        $this->assertStringContainsString('professional', $enhancedPrompt);
        $this->assertStringContainsString('developers', $enhancedPrompt);
    }

    public function test_analyze_content_against_brand_voice()
    {
        // Create a brand voice
        $brandVoiceData = [
            'name' => 'Professional Brand',
            'tone' => 'professional',
            'avoid_words' => ['cheap', 'basic', 'simple'],
        ];

        $created = $this->brandVoiceManager->createBrandVoice('user-123', $brandVoiceData);
        $brandVoiceId = $created['id'];

        $content = 'This is a cheap and basic solution for simple problems.';
        $analysis = $this->brandVoiceManager->analyzeContent('user-123', $brandVoiceId, $content);

        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('score', $analysis);
        $this->assertArrayHasKey('issues', $analysis);
        $this->assertArrayHasKey('suggestions', $analysis);
        
        // Should detect avoid words
        $this->assertGreaterThan(0, count($analysis['issues']));
        $this->assertLessThan(80, $analysis['score']); // Low score due to avoid words
    }

    public function test_validate_brand_voice_data()
    {
        $validData = [
            'name' => 'Valid Brand',
            'tone' => 'professional',
            'style' => 'informative',
        ];

        $this->assertTrue($this->brandVoiceManager->validateBrandVoiceData($validData));
    }

    public function test_validate_brand_voice_data_missing_required_fields()
    {
        $invalidData = [
            'tone' => 'professional',
            // Missing required 'name' field
        ];

        $this->assertFalse($this->brandVoiceManager->validateBrandVoiceData($invalidData));
    }

    public function test_get_brand_voice_suggestions()
    {
        $suggestions = $this->brandVoiceManager->getBrandVoiceSuggestions('technology');

        $this->assertIsArray($suggestions);
        $this->assertNotEmpty($suggestions);
        
        foreach ($suggestions as $suggestion) {
            $this->assertArrayHasKey('name', $suggestion);
            $this->assertArrayHasKey('tone', $suggestion);
            $this->assertArrayHasKey('style', $suggestion);
            $this->assertArrayHasKey('description', $suggestion);
        }
    }

    public function test_export_brand_voice()
    {
        // Create a brand voice
        $brandVoiceData = [
            'name' => 'Export Test',
            'tone' => 'professional',
            'style' => 'informative',
        ];

        $created = $this->brandVoiceManager->createBrandVoice('user-123', $brandVoiceData);
        $brandVoiceId = $created['id'];

        $exported = $this->brandVoiceManager->exportBrandVoice('user-123', $brandVoiceId);

        $this->assertIsArray($exported);
        $this->assertEquals('Export Test', $exported['name']);
        $this->assertArrayHasKey('export_version', $exported);
        $this->assertArrayHasKey('exported_at', $exported);
    }

    public function test_import_brand_voice()
    {
        $importData = [
            'name' => 'Imported Brand',
            'tone' => 'casual',
            'style' => 'friendly',
            'export_version' => '1.0',
            'exported_at' => now()->toISOString(),
        ];

        $imported = $this->brandVoiceManager->importBrandVoice('user-123', $importData);

        $this->assertIsArray($imported);
        $this->assertEquals('Imported Brand', $imported['name']);
        $this->assertEquals('casual', $imported['tone']);
        $this->assertArrayHasKey('id', $imported);
    }
}
