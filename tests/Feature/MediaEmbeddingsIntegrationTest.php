<?php

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Traits\HasMediaEmbeddings;
use LaravelAIEngine\Traits\Vectorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class MediaEmbeddingsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test table
        Schema::create('test_media_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('image_path')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('video_path')->nullable();
            $table->string('document_path')->nullable();
            $table->timestamps();
        });

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_media_posts');
        parent::tearDown();
    }

    /** @test */
    public function it_generates_vector_content_with_text_and_media()
    {
        $post = new TestMediaPost();
        $post->title = 'My Blog Post';
        $post->content = 'This is the content of my blog post.';
        $post->image_path = 'images/test.jpg';
        $post->save();

        $vectorContent = $post->getVectorContent();

        // Should contain text content
        $this->assertStringContainsString('My Blog Post', $vectorContent);
        $this->assertStringContainsString('This is the content of my blog post', $vectorContent);
        
        // Vector content should not be empty
        $this->assertNotEmpty($vectorContent);
    }

    /** @test */
    public function it_works_without_media_fields()
    {
        $post = new TestMediaPost();
        $post->title = 'Text Only Post';
        $post->content = 'Just text content.';
        $post->save();

        $vectorContent = $post->getVectorContent();

        $this->assertStringContainsString('Text Only Post', $vectorContent);
        $this->assertStringContainsString('Just text content', $vectorContent);
    }

    /** @test */
    public function it_handles_multiple_media_types()
    {
        $post = new TestMediaPost();
        $post->title = 'Multi-Media Post';
        $post->content = 'Post with multiple media types.';
        $post->image_path = 'images/photo.jpg';
        $post->audio_path = 'audio/podcast.mp3';
        $post->video_path = 'videos/tutorial.mp4';
        $post->save();

        $vectorContent = $post->getVectorContent();

        // Should contain text
        $this->assertStringContainsString('Multi-Media Post', $vectorContent);
        $this->assertNotEmpty($vectorContent);
    }

    /** @test */
    public function it_can_be_saved_and_retrieved()
    {
        $post = TestMediaPost::create([
            'title' => 'Saved Post',
            'content' => 'This post is saved to database.',
            'image_path' => 'images/saved.jpg',
        ]);

        $retrieved = TestMediaPost::find($post->id);

        $this->assertEquals('Saved Post', $retrieved->title);
        $this->assertEquals('images/saved.jpg', $retrieved->image_path);
        
        $vectorContent = $retrieved->getVectorContent();
        $this->assertStringContainsString('Saved Post', $vectorContent);
    }

    /** @test */
    public function it_generates_different_content_for_different_models()
    {
        $post1 = TestMediaPost::create([
            'title' => 'First Post',
            'content' => 'First content',
        ]);

        $post2 = TestMediaPost::create([
            'title' => 'Second Post',
            'content' => 'Second content',
        ]);

        $content1 = $post1->getVectorContent();
        $content2 = $post2->getVectorContent();

        $this->assertNotEquals($content1, $content2);
        $this->assertStringContainsString('First Post', $content1);
        $this->assertStringContainsString('Second Post', $content2);
    }

    /** @test */
    public function it_logs_debug_information_when_enabled()
    {
        config(['ai-engine.debug' => true]);

        \Log::shouldReceive('channel')
            ->with('ai-engine')
            ->andReturnSelf();

        \Log::shouldReceive('debug')
            ->withAnyArgs()
            ->andReturnNull();

        \Log::shouldReceive('warning')
            ->withAnyArgs()
            ->andReturnNull();

        \Log::shouldReceive('info')
            ->withAnyArgs()
            ->andReturnNull();

        $post = new TestMediaPost();
        $post->title = 'Debug Test';
        $post->content = 'Testing debug logs';
        $post->image_path = 'images/test.jpg';

        $content = $post->getVectorContent();
        $this->assertNotNull($content);
    }
}

/**
 * Test model for integration testing
 */
class TestMediaPost extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    protected $table = 'test_media_posts';
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->vectorizable = ['title', 'content'];
        $this->mediaFields = [
            'image' => 'image_path',
            'audio' => 'audio_path',
            'video' => 'video_path',
            'document' => 'document_path',
        ];
    }
}
