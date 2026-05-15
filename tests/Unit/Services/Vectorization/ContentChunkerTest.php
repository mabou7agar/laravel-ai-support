<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vectorization;

use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Services\Vectorization\ContentChunker;
use LaravelAIEngine\Services\Vectorization\TokenCalculator;
use LaravelAIEngine\Tests\UnitTestCase;

class ContentChunkerTest extends UnitTestCase
{
    public function test_truncate_uses_detected_token_profile_when_no_fixed_limit_is_configured(): void
    {
        Config::set('ai-engine.vectorization.max_content_length', null);

        $chunker = new ContentChunker(new TokenCalculator());

        $latin = str_repeat('plain invoice description ', 60);
        $code = str_repeat('public function total() { return $this->amount; } ', 35);

        $this->assertSame($latin, $chunker->truncate($latin, 'embed-english-v3.0'));
        $this->assertLessThanOrEqual(922, strlen($chunker->truncate($code, 'embed-english-v3.0')));
    }

    public function test_split_uses_detected_token_profile_for_default_chunk_size(): void
    {
        Config::set('ai-engine.vectorization.chunk_size', null);
        Config::set('ai-engine.vectorization.chunk_overlap', 0);
        Config::set('ai-engine.vector.embedding_model', 'embed-english-v3.0');

        $chunker = new ContentChunker(new TokenCalculator());

        $latin = str_repeat('plain invoice description. ', 80);
        $code = str_repeat('public function total() { return $this->amount; } ', 50);

        $this->assertCount(2, $chunker->split($latin, 'Invoice'));
        $this->assertGreaterThan(2, count($chunker->split($code, 'Invoice')));
    }
}
