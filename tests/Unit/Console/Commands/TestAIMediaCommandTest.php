<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Console\Commands\TestAIMediaCommand;
use LaravelAIEngine\Models\AIMedia;
use LaravelAIEngine\Tests\TestCase;

class TestAIMediaCommandTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(TestAIMediaCommand::class));
    }

    public function test_command_signature(): void
    {
        $command = new TestAIMediaCommand();

        $this->assertSame('ai-engine:test-ai-media', $command->getName());
    }

    public function test_command_outputs_json_summary_and_recent_rows(): void
    {
        AIMedia::query()->create([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'engine' => 'fal_ai',
            'ai_model' => 'fal-ai/nano-banana-2',
            'content_type' => 'image',
            'collection_name' => 'generated-images',
            'name' => 'hero',
            'file_name' => 'hero.png',
            'disk' => 'public',
            'size' => 123,
            'path' => 'ai-generated/fal_ai/image/hero.png',
            'url' => 'https://cdn.example.com/hero.png',
        ]);

        $exitCode = Artisan::call('ai-engine:test-ai-media', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"table_exists": true', $output);
        $this->assertStringContainsString('"recent_media"', $output);
        $this->assertStringContainsString('https://cdn.example.com/hero.png', $output);
    }

    public function test_command_can_write_and_cleanup_test_media(): void
    {
        Storage::fake('public');
        config()->set('ai-engine.media_library.disk', 'public');

        $exitCode = Artisan::call('ai-engine:test-ai-media', [
            '--write-test' => true,
            '--cleanup' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"exists_on_disk": true', $output);
        $this->assertStringContainsString('"cleaned_up": true', $output);
        $this->assertDatabaseCount('ai_media', 0);
    }
}
