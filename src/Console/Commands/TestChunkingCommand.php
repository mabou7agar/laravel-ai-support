<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Traits\Vectorizable;

class TestChunkingCommand extends Command
{
    protected $signature = 'ai-engine:test-chunking 
                            {--strategy=split : Strategy to test (split or truncate)}
                            {--size=50000 : Content size in characters}
                            {--cleanup : Clean up test data after running}';

    protected $description = 'Test content chunking strategies';

    public function handle()
    {
        $this->info('🧪 Testing Content Chunking Strategies');
        $this->newLine();

        $strategy = $this->option('strategy');
        $size = (int) $this->option('size');

        // Enable debug logging
        config(['ai-engine.debug' => true]);
        config(['ai-engine.vectorization.strategy' => $strategy]);

        $this->info("📋 Configuration:");
        $this->line("   Strategy: {$strategy}");
        $this->line("   Content size: " . number_format($size) . " chars");
        $this->newLine();

        try {
            // Create test table
            $this->info('📋 Step 1: Creating test table...');
            $this->createTestTable();
            $this->line('   ✅ Test table created');
            $this->newLine();

            // Test with different content sizes
            $this->info('🔧 Step 2: Testing chunking...');
            $this->testChunking($strategy, $size);
            $this->newLine();

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
        Schema::dropIfExists('test_chunking_posts');
        
        Schema::create('test_chunking_posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->longText('content');
            $table->timestamps();
        });
    }

    protected function testChunking(string $strategy, int $size)
    {
        // Create test model class
        $model = new class extends Model {
            use Vectorizable;
            
            protected $table = 'test_chunking_posts';
            protected $guarded = [];
            
            public function __construct(array $attributes = [])
            {
                parent::__construct($attributes);
                $this->vectorizable = ['title', 'content'];
            }
        };

        // Generate content of specified size
        $content = $this->generateContent($size);
        
        $this->line("   📝 Creating test post with {$size} chars content...");
        
        $post = $model->create([
            'title' => 'Test Chunking Post',
            'content' => $content,
        ]);

        $this->line("   ✅ Post created (ID: {$post->id})");
        $this->newLine();

        if ($strategy === 'split') {
            $this->testSplitStrategy($post);
        } else {
            $this->testTruncateStrategy($post);
        }
    }

    protected function testSplitStrategy($post)
    {
        $this->line('   🔀 Testing SPLIT strategy...');
        $this->newLine();

        // Get all chunks
        $chunks = $post->getVectorContentChunks();
        
        $this->line('   📊 Results:');
        $this->line('   ─────────────────────────────');
        $this->line('   Total chunks: ' . count($chunks));
        $this->newLine();

        // Show chunk details
        $this->line('   📦 Chunk Details:');
        foreach ($chunks as $index => $chunk) {
            $chunkNum = $index + 1;
            $length = strlen($chunk);
            $preview = substr($chunk, 0, 50) . '...';
            
            $this->line("   Chunk {$chunkNum}:");
            $this->line("      Length: " . number_format($length) . " chars");
            $this->line("      Preview: {$preview}");
            $this->newLine();
        }

        // Show statistics
        $totalLength = array_sum(array_map('strlen', $chunks));
        $avgLength = $totalLength / count($chunks);
        
        $this->line('   📈 Statistics:');
        $this->line('   ─────────────────────────────');
        $this->line('   Total content: ' . number_format($totalLength) . ' chars');
        $this->line('   Average chunk: ' . number_format($avgLength) . ' chars');
        $this->line('   Min chunk: ' . number_format(min(array_map('strlen', $chunks))) . ' chars');
        $this->line('   Max chunk: ' . number_format(max(array_map('strlen', $chunks))) . ' chars');
        $this->newLine();

        // Check overlap
        if (count($chunks) > 1) {
            $overlap = $this->detectOverlap($chunks[0], $chunks[1]);
            $this->line('   🔗 Overlap Detection:');
            $this->line('   ─────────────────────────────');
            $this->line('   Overlap between chunks 1-2: ' . $overlap . ' chars');
            if ($overlap > 0) {
                $this->line('   ✅ Overlap detected (maintains context)');
            }
        }
    }

    protected function testTruncateStrategy($post)
    {
        $this->line('   ✂️  Testing TRUNCATE strategy...');
        $this->newLine();

        // Get truncated content
        $content = $post->getVectorContent();
        
        $this->line('   📊 Results:');
        $this->line('   ─────────────────────────────');
        $this->line('   Content length: ' . number_format(strlen($content)) . ' chars');
        $this->newLine();

        // Show preview
        $preview = substr($content, 0, 100) . '...';
        $this->line('   📄 Preview:');
        $this->line('   ' . $preview);
        $this->newLine();

        // Check if truncated
        $originalLength = strlen($post->content) + strlen($post->title);
        if (strlen($content) < $originalLength) {
            $lost = $originalLength - strlen($content);
            $lostPercent = ($lost / $originalLength) * 100;
            
            $this->line('   ⚠️  Content was truncated:');
            $this->line('   ─────────────────────────────');
            $this->line('   Original: ' . number_format($originalLength) . ' chars');
            $this->line('   Truncated: ' . number_format(strlen($content)) . ' chars');
            $this->line('   Lost: ' . number_format($lost) . ' chars (' . number_format($lostPercent, 1) . '%)');
        } else {
            $this->line('   ✅ No truncation needed (content fits)');
        }
    }

    protected function generateContent(int $size): string
    {
        $sentences = [
            'This is a test sentence with important content about artificial intelligence.',
            'Machine learning models require large amounts of training data.',
            'Natural language processing enables computers to understand human language.',
            'Vector embeddings represent text in high-dimensional space.',
            'Retrieval-augmented generation improves AI response accuracy.',
            'Chunking strategies help manage large documents effectively.',
            'Token limits constrain the amount of text that can be processed.',
            'Semantic search finds relevant content based on meaning.',
            'Context windows determine how much information AI can consider.',
            'Embedding models convert text into numerical representations.',
        ];

        $content = '';
        $sentenceIndex = 0;

        while (strlen($content) < $size) {
            $content .= $sentences[$sentenceIndex % count($sentences)] . ' ';
            $sentenceIndex++;
        }

        return substr($content, 0, $size);
    }

    protected function detectOverlap(string $chunk1, string $chunk2): int
    {
        $maxOverlap = min(strlen($chunk1), strlen($chunk2));
        
        for ($i = $maxOverlap; $i > 0; $i--) {
            $end1 = substr($chunk1, -$i);
            $start2 = substr($chunk2, 0, $i);
            
            if ($end1 === $start2) {
                return $i;
            }
        }
        
        return 0;
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

        // Get last 30 lines
        $lines = array_slice(file($logFile), -30);
        
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Content split into chunks') || 
                str_contains($line, 'Vector content generated') ||
                str_contains($line, 'Large fields chunked')) {
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
        if (Schema::hasTable('test_chunking_posts')) {
            Schema::drop('test_chunking_posts');
        }
    }
}
