<?php

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Tests\UnitTestCase;
use ReflectionProperty;

class EmbeddingServiceFakeModeTest extends UnitTestCase
{
    public function test_fake_embedding_mode_is_deterministic_and_dimensioned(): void
    {
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);

        /** @var EmbeddingService $service */
        $service = $this->app->make(EmbeddingService::class);

        $a = $service->embed('local release checklist');
        $b = $service->embed('local release checklist');
        $c = $service->embed('remote policy handbook');

        $this->assertSame($service->getDimensions(), count($a));
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_fake_embedding_mode_reads_config_not_runtime_env(): void
    {
        putenv('AI_ENGINE_USE_FAKE_EMBEDDINGS=true');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', false);

        try {
            /** @var EmbeddingService $service */
            $service = $this->app->make(EmbeddingService::class);

            $property = new ReflectionProperty($service, 'useFakeEmbeddings');
            $property->setAccessible(true);

            $this->assertFalse($property->getValue($service));
        } finally {
            putenv('AI_ENGINE_USE_FAKE_EMBEDDINGS');
        }
    }
}
