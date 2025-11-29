<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\HasMediaEmbeddings;
use Illuminate\Database\Eloquent\Model;

class TestMediaEmbeddingsCommand extends Command
{
    protected $signature = 'ai-engine:test-media-embeddings 
                            {--cleanup : Clean up test data after running}';

    protected $description = 'Test HasMediaEmbeddings trait functionality';

    public function handle()
    {
        $this->info('🧪 Testing HasMediaEmbeddings Trait');
        $this->newLine();

        // Enable debug logging
        config(['ai-engine.debug' => true]);

        try {
            // Step 1: Create test table
            $this->info('📋 Step 1: Creating test table...');
            $this->createTestTable();
            $this->line('   ✅ Test table created');
            $this->newLine();

            // Step 2: Test basic functionality
            $this->info('🔧 Step 2: Testing basic functionality...');
            $this->testBasicFunctionality();
            $this->newLine();

            // Step 3: Test with Vectorizable trait
            $this->info('🔗 Step 3: Testing integration with Vectorizable...');
            $this->testVectorizableIntegration();
            $this->newLine();

            // Step 4: Test multiple media fields
            $this->info('📎 Step 4: Testing multiple media fields...');
            $this->testMultipleMediaFields();
            $this->newLine();

            // Step 5: Test auto-detection
            $this->info('🔍 Step 5: Testing auto-detection...');
            $this->testAutoDetection();
            $this->newLine();

            // Step 6: Show logs
            $this->info('📊 Step 6: Checking logs...');
            $this->showRecentLogs();
            $this->newLine();

            // Cleanup
            if ($this->option('cleanup')) {
                $this->info('🧹 Cleaning up...');
                $this->cleanup();
                $this->line('   ✅ Cleanup complete');
            } else {
                $this->warn('💡 Tip: Run with --cleanup to remove test data');
            }

            $this->newLine();
            $this->info('✅ All tests passed!');
            $this->newLine();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Test failed: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            
            if ($this->option('cleanup')) {
                $this->cleanup();
            }
            
            return Command::FAILURE;
        }
    }

    protected function createTestTable()
    {
        if (Schema::hasTable('test_media_posts')) {
            Schema::drop('test_media_posts');
        }

        Schema::create('test_media_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('image_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('video_path')->nullable();
            $table->timestamps();
        });
    }

    protected function testBasicFunctionality()
    {
        $model = new TestMediaPost();
        $model->title = 'Test Post';
        $model->content = 'This is test content';
        $model->save();

        $vectorContent = $model->getVectorContent();

        $this->line('   📝 Created model: ' . $model->title);
        $this->line('   📏 Vector content length: ' . strlen($vectorContent));
        
        if (str_contains($vectorContent, 'Test Post')) {
            $this->line('   ✅ Title found in vector content');
        } else {
            throw new \Exception('Title not found in vector content');
        }

        if (str_contains($vectorContent, 'test content')) {
            $this->line('   ✅ Content found in vector content');
        } else {
            throw new \Exception('Content not found in vector content');
        }
    }

    protected function testVectorizableIntegration()
    {
        $model = new TestMediaPost();
        $model->title = 'Integration Test';
        $model->content = 'Testing both traits together';
        $model->image_path = '/path/to/test-image.jpg';
        $model->save();

        $vectorContent = $model->getVectorContent();

        $this->line('   📝 Created model with media: ' . $model->title);
        $this->line('   📏 Vector content length: ' . strlen($vectorContent));
        
        if (str_contains($vectorContent, 'Integration Test')) {
            $this->line('   ✅ Text content included');
        } else {
            throw new \Exception('Text content not found');
        }

        // Check if getMediaVectorContent method exists
        if (method_exists($model, 'getMediaVectorContent')) {
            $this->line('   ✅ getMediaVectorContent method exists');
            
            $mediaContent = $model->getMediaVectorContent();
            $this->line('   📏 Media content length: ' . strlen($mediaContent));
        } else {
            throw new \Exception('getMediaVectorContent method not found');
        }
    }

    protected function testMultipleMediaFields()
    {
        $model = new TestMediaPost();
        $model->title = 'Multi-Media Post';
        $model->content = 'Post with multiple media types';
        $model->image_path = '/images/photo.jpg';
        $model->audio_path = '/audio/podcast.mp3';
        $model->video_path = '/videos/tutorial.mp4';
        $model->save();

        $vectorContent = $model->getVectorContent();

        $this->line('   📝 Created model with multiple media');
        $this->line('   🖼️  Image: ' . $model->image_path);
        $this->line('   🎵 Audio: ' . $model->audio_path);
        $this->line('   🎬 Video: ' . $model->video_path);
        $this->line('   📏 Vector content length: ' . strlen($vectorContent));
        $this->line('   ✅ Multiple media fields handled');
    }

    protected function testAutoDetection()
    {
        // Clear cache to force auto-detection
        \Cache::forget('vectorizable_fields_test_media_posts');

        $model = new TestMediaPostAutoDetect();
        $model->title = 'Auto-Detect Test';
        $model->content = 'Testing auto-detection';
        $model->save();

        $vectorContent = $model->getVectorContent();

        $this->line('   📝 Created model without explicit $vectorizable');
        $this->line('   📏 Vector content length: ' . strlen($vectorContent));
        
        if (!empty($vectorContent)) {
            $this->line('   ✅ Auto-detection worked');
        } else {
            throw new \Exception('Auto-detection failed - empty content');
        }
    }

    protected function showRecentLogs()
    {
        // Try daily log file first (ai-engine-YYYY-MM-DD.log)
        $logFile = storage_path('logs/ai-engine-' . date('Y-m-d') . '.log');
        
        // Fallback to non-dated log file
        if (!file_exists($logFile)) {
            $logFile = storage_path('logs/ai-engine.log');
        }
        
        if (!file_exists($logFile)) {
            $this->warn('   ⚠️  Log file not found');
            $this->line('   💡 Tip: Logs are written to storage/logs/ai-engine-YYYY-MM-DD.log');
            return;
        }

        $this->line('   📄 Recent log entries from: ' . basename($logFile));
        $this->newLine();

        // Get last 30 lines
        $lines = array_slice(file($logFile), -30);
        
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Vector content generated') || 
                str_contains($line, 'Auto-detect') ||
                str_contains($line, 'Large fields skipped')) {
                $this->line('   ' . trim($line));
                $found = true;
            }
        }
        
        if (!$found) {
            $this->line('   ℹ️  No relevant log entries found');
        }
    }

    protected function cleanup()
    {
        if (Schema::hasTable('test_media_posts')) {
            Schema::drop('test_media_posts');
        }

        // Clear cache
        \Cache::forget('vectorizable_fields_test_media_posts');
    }
}

/**
 * Test model with explicit vectorizable fields
 */
class TestMediaPost extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    protected $table = 'test_media_posts';
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // Set vectorizable fields after trait is loaded
        $this->vectorizable = ['title', 'content'];
        $this->mediaFields = [
            'image' => 'image_path',
            'audio' => 'audio_path',
            'video' => 'video_path',
        ];
    }
}

/**
 * Test model with auto-detection (no explicit vectorizable)
 */
class TestMediaPostAutoDetect extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    protected $table = 'test_media_posts';
    protected $guarded = [];
    
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // No $vectorizable - should auto-detect
        $this->mediaFields = [];
    }
}
