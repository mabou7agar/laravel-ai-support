<?php

namespace LaravelAIEngine\Tests\Unit\Traits;

use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\HasMediaEmbeddings;
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Services\Media\MediaEmbeddingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mockery;

class HasMediaEmbeddingsTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_get_media_vector_content()
    {
        $model = new TestModelWithMedia();
        $model->title = 'Test Title';
        $model->image_path = '/path/to/image.jpg';
        $model->mediaFields = ['image' => 'image_path'];

        // Mock MediaEmbeddingService
        $mediaService = Mockery::mock(MediaEmbeddingService::class);
        $mediaService->shouldReceive('getMediaContent')
            ->once()
            ->with($model, 'image_path')
            ->andReturn('Image description: A beautiful sunset');

        $this->app->instance(MediaEmbeddingService::class, $mediaService);

        $content = $model->getMediaVectorContent();

        $this->assertStringContainsString('Image description: A beautiful sunset', $content);
    }

    /** @test */
    public function it_returns_empty_string_when_no_media_fields()
    {
        $model = new TestModelWithMedia();
        $model->title = 'Test Title';
        $model->mediaFields = [];

        $content = $model->getMediaVectorContent();

        $this->assertEquals('', $content);
    }

    /** @test */
    public function it_handles_multiple_media_fields()
    {
        $model = new TestModelWithMedia();
        $model->image_path = '/path/to/image.jpg';
        $model->audio_path = '/path/to/audio.mp3';
        $model->mediaFields = [
            'image' => 'image_path',
            'audio' => 'audio_path'
        ];

        // Mock MediaEmbeddingService
        $mediaService = Mockery::mock(MediaEmbeddingService::class);
        $mediaService->shouldReceive('getMediaContent')
            ->once()
            ->with($model, 'image_path')
            ->andReturn('Image: sunset');
        $mediaService->shouldReceive('getMediaContent')
            ->once()
            ->with($model, 'audio_path')
            ->andReturn('Audio: music');

        $this->app->instance(MediaEmbeddingService::class, $mediaService);

        $content = $model->getMediaVectorContent();

        $this->assertStringContainsString('Image: sunset', $content);
        $this->assertStringContainsString('Audio: music', $content);
    }

    /** @test */
    public function it_skips_missing_media_fields()
    {
        $model = new TestModelWithMedia();
        $model->mediaFields = ['image' => 'image_path'];
        // image_path is not set

        $content = $model->getMediaVectorContent();

        $this->assertEquals('', $content);
    }

    /** @test */
    public function it_integrates_with_vectorizable_trait()
    {
        $model = new TestModelWithBothTraits();
        $model->title = 'Test Title';
        $model->content = 'Test Content';
        $model->image_path = '/path/to/image.jpg';
        $model->vectorizable = ['title', 'content'];
        $model->mediaFields = ['image' => 'image_path'];

        // Mock MediaEmbeddingService
        $mediaService = Mockery::mock(MediaEmbeddingService::class);
        $mediaService->shouldReceive('getMediaContent')
            ->once()
            ->with($model, 'image_path')
            ->andReturn('Image description');

        $this->app->instance(MediaEmbeddingService::class, $mediaService);

        $vectorContent = $model->getVectorContent();

        // Should contain text fields
        $this->assertStringContainsString('Test Title', $vectorContent);
        $this->assertStringContainsString('Test Content', $vectorContent);
        // Should contain media content
        $this->assertStringContainsString('Image description', $vectorContent);
    }

    /** @test */
    public function it_logs_vector_content_generation_with_media()
    {
        config(['ai-engine.debug' => true]);
        
        Log::shouldReceive('channel')
            ->with('ai-engine')
            ->andReturnSelf();
        
        Log::shouldReceive('debug')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Vector content generated' 
                    && $context['has_media'] === true
                    && in_array('title', $context['fields_used'])
                    && in_array('content', $context['fields_used']);
            });

        $model = new TestModelWithBothTraits();
        $model->title = 'Test';
        $model->content = 'Content';
        $model->image_path = '/image.jpg';
        $model->vectorizable = ['title', 'content'];
        $model->mediaFields = ['image' => 'image_path'];

        // Mock MediaEmbeddingService
        $mediaService = Mockery::mock(MediaEmbeddingService::class);
        $mediaService->shouldReceive('getMediaContent')
            ->andReturn('Image description');

        $this->app->instance(MediaEmbeddingService::class, $mediaService);

        $model->getVectorContent();
    }

    /** @test */
    public function it_handles_media_service_errors_gracefully()
    {
        $model = new TestModelWithMedia();
        $model->image_path = '/path/to/image.jpg';
        $model->mediaFields = ['image' => 'image_path'];

        // Mock MediaEmbeddingService to return null (error case)
        $mediaService = Mockery::mock(MediaEmbeddingService::class);
        $mediaService->shouldReceive('getMediaContent')
            ->once()
            ->andReturn(null);

        $this->app->instance(MediaEmbeddingService::class, $mediaService);

        $content = $model->getMediaVectorContent();

        // Should return empty string, not throw exception
        $this->assertEquals('', $content);
    }
}

/**
 * Test model with HasMediaEmbeddings trait only
 */
class TestModelWithMedia extends Model
{
    use HasMediaEmbeddings;

    protected $table = 'test_models';
    protected $guarded = [];
    public $timestamps = false;
}

/**
 * Test model with both Vectorizable and HasMediaEmbeddings traits
 */
class TestModelWithBothTraits extends Model
{
    use Vectorizable, HasMediaEmbeddings;

    protected $table = 'test_models';
    protected $guarded = [];
    public $timestamps = false;
}
