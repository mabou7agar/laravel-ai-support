<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\HasMediaEmbeddings;

class TestLargeMediaCommand extends Command
{
    protected $signature = 'ai:test-large-media
                            {--url= : Custom media URL to test}
                            {--type=video : Media type (video, image, document, audio)}
                            {--cleanup : Clean up test data after running}';

    protected $description = 'Test large media file handling and size limits';

    public function handle()
    {
        $this->info('🧪 Testing Large Media File Handling');
        $this->newLine();

        // Enable debug logging
        config(['ai-engine.debug' => true]);

        $customUrl = $this->option('url');
        $type = $this->option('type');

        try {
            // Create test table
            $this->info('📋 Step 1: Creating test table...');
            $this->createTestTable();
            $this->line('   ✅ Test table created');
            $this->newLine();

            // Test different scenarios
            $this->info('🔧 Step 2: Testing media scenarios...');
            $this->newLine();

            if ($customUrl) {
                $this->testCustomUrl($customUrl, $type);
            } else {
                $this->testPredefinedScenarios();
            }

            // Show logs
            $this->info('📊 Step 3: Checking logs...');
            $this->showRecentLogs();
            $this->newLine();

            $this->info('✅ All tests complete!');

        } catch (\Exception $e) {
            $this->error('❌ Test failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        } finally {
            if ($this->option('cleanup')) {
                $this->info('🧹 Cleaning up...');
                $this->cleanup();
                $this->line('   ✅ Cleanup complete');
            }
        }

        return 0;
    }

    protected function createTestTable()
    {
        Schema::dropIfExists('test_large_media');

        Schema::create('test_large_media', function ($table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('media_url')->nullable();
            $table->timestamps();
        });
    }

    protected function testPredefinedScenarios()
    {
        $scenarios = [
            [
                'name' => 'Small Image (Should Work)',
                'url' => 'https://via.placeholder.com/150',
                'type' => 'image',
                'expected' => 'success',
            ],
            [
                'name' => 'Medium Image (Should Work)',
                'url' => 'https://via.placeholder.com/1920x1080',
                'type' => 'image',
                'expected' => 'success',
            ],
            [
                'name' => 'Sample Video URL (May be large)',
                'url' => 'https://sample-videos.com/video123/mp4/720/big_buck_bunny_720p_1mb.mp4',
                'type' => 'video',
                'expected' => 'depends on size',
            ],
        ];

        foreach ($scenarios as $index => $scenario) {
            $this->line("   📦 Scenario " . ($index + 1) . ": {$scenario['name']}");
            $this->line("   URL: {$scenario['url']}");
            $this->newLine();

            $this->testMediaUrl($scenario['url'], $scenario['type'], $scenario['name']);
            $this->newLine();
        }
    }

    protected function testCustomUrl(string $url, string $type)
    {
        $this->line("   📦 Testing Custom URL");
        $this->line("   URL: {$url}");
        $this->line("   Type: {$type}");
        $this->newLine();

        $this->testMediaUrl($url, $type, 'Custom Media');
    }

    protected function testMediaUrl(string $url, string $type, string $name)
    {
        // Create test model class
        $model = new class extends Model {
            use Vectorizable, HasMediaEmbeddings;

            protected $table = 'test_large_media';
            protected $guarded = [];

            public function __construct(array $attributes = [])
            {
                parent::__construct($attributes);
                $this->vectorizable = ['title', 'description'];
                $this->mediaFields = [$attributes['_type'] ?? 'image' => 'media_url'];
            }
        };

        // Check URL accessibility and size
        $this->line('   🔍 Checking URL...');
        $urlInfo = $this->checkUrl($url);

        if (!$urlInfo['accessible']) {
            $this->warn('   ⚠️  URL not accessible');
            $this->line('   Error: ' . ($urlInfo['error'] ?? 'Unknown'));
            return;
        }

        $this->line('   ✅ URL accessible');

        if (isset($urlInfo['size'])) {
            $sizeMB = round($urlInfo['size'] / 1048576, 2);
            $this->line('   📏 File size: ' . number_format($urlInfo['size']) . ' bytes (' . $sizeMB . ' MB)');

            $maxSize = config('ai-engine.vectorization.max_media_file_size', 10485760);
            $maxSizeMB = round($maxSize / 1048576, 2);

            if ($urlInfo['size'] > $maxSize) {
                $this->warn('   ⚠️  File exceeds limit (' . $maxSizeMB . ' MB)');
                $this->line('   Expected: Download will be skipped');
            } else {
                $this->line('   ✅ File within limit (' . $maxSizeMB . ' MB)');
                $this->line('   Expected: Download will proceed');
            }
        } else {
            $this->line('   ℹ️  File size unknown (will check during download)');
        }

        $this->newLine();
        $this->line('   📝 Creating model with media...');

        // Create model with media URL
        $post = $model->create([
            'title' => $name,
            'description' => 'Testing large media handling',
            'media_url' => $url,
            '_type' => $type,
        ]);

        $this->line('   ✅ Model created (ID: ' . $post->id . ')');
        $this->newLine();

        // Try to get vector content
        $this->line('   🔄 Generating vector content...');

        try {
            $startTime = microtime(true);
            $vectorContent = $post->getVectorContent();
            $endTime = microtime(true);

            $processingTime = round(($endTime - $startTime) * 1000, 2);

            $this->line('   ✅ Vector content generated');
            $this->line('   ⏱️  Processing time: ' . $processingTime . ' ms');
            $this->line('   📏 Content length: ' . number_format(strlen($vectorContent)) . ' chars');
            $this->newLine();

            // Show preview
            $preview = substr($vectorContent, 0, 200);
            $this->line('   📄 Content preview:');
            $this->line('   ' . $preview . '...');
            $this->newLine();

            // Check if media was included
            if (strlen($vectorContent) > strlen($post->title) + strlen($post->description ?? '')) {
                $this->line('   ✅ Media content appears to be included');
            } else {
                $this->warn('   ⚠️  Media content may not be included (check logs)');
            }

        } catch (\Exception $e) {
            $this->error('   ❌ Error generating vector content');
            $this->error('   ' . $e->getMessage());
        }
    }

    protected function checkUrl(string $url): array
    {
        $result = [
            'accessible' => false,
            'size' => null,
            'error' => null,
        ];

        try {
            $headers = @get_headers($url, 1);

            if ($headers === false) {
                $result['error'] = 'Failed to get headers';
                return $result;
            }

            // Check if URL is accessible (200 OK)
            if (isset($headers[0])) {
                $statusLine = is_array($headers[0]) ? end($headers[0]) : $headers[0];
                if (strpos($statusLine, '200') !== false) {
                    $result['accessible'] = true;
                } else {
                    $result['error'] = $statusLine;
                    return $result;
                }
            }

            // Get file size
            if (isset($headers['Content-Length'])) {
                $size = is_array($headers['Content-Length'])
                    ? end($headers['Content-Length'])
                    : $headers['Content-Length'];
                $result['size'] = (int) $size;
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    protected function showRecentLogs()
    {
        // Try daily log file first
        $logFile = storage_path('logs/ai-engine-' . date('Y-m-d') . '.log');

        if (!file_exists($logFile)) {
            $logFile = storage_path('logs/ai-engine.log');
        }

        if (!file_exists($logFile)) {
            $this->warn('   ⚠️  Log file not found');
            return;
        }

        $this->line('   📄 Recent log entries from: ' . basename($logFile));
        $this->newLine();

        // Get last 50 lines
        $lines = array_slice(file($logFile), -50);

        $found = false;
        $relevantPatterns = [
            'Media file too large',
            'Downloaded file too large',
            'Media content truncated',
            'Failed to download URL',
            'Processed URL media',
            'Media content integrated',
        ];

        foreach ($lines as $line) {
            foreach ($relevantPatterns as $pattern) {
                if (str_contains($line, $pattern)) {
                    $this->line('   ' . trim($line));
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            $this->line('   ℹ️  No relevant log entries found');
        }
    }

    protected function cleanup()
    {
        if (Schema::hasTable('test_large_media')) {
            Schema::drop('test_large_media');
        }
    }
}
