<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class AIRequestProviderOptionsTest extends UnitTestCase
{
    public function test_provider_options_can_be_generic_and_provider_specific(): void
    {
        $request = (new AIRequest('hello', EngineEnum::OPENAI, EntityEnum::GPT_4O))
            ->withProviderOptions(['store' => true, 'metadata' => ['global' => true]])
            ->withProviderOptions(['background' => true, 'metadata' => ['provider' => 'openai']], 'openai');

        $this->assertSame([
            'store' => true,
            'metadata' => ['global' => true, 'provider' => 'openai'],
            'background' => true,
        ], $request->getProviderOptions('openai'));

        $this->assertArrayHasKey('provider_options', $request->toArray());
    }
}
