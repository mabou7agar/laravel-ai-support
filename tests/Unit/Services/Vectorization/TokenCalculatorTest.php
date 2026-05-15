<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vectorization;

use LaravelAIEngine\Services\Vectorization\TokenCalculator;
use LaravelAIEngine\Tests\UnitTestCase;

class TokenCalculatorTest extends UnitTestCase
{
    public function test_estimates_cjk_text_more_densely_than_latin_text(): void
    {
        $calculator = new TokenCalculator();

        $latin = str_repeat('hello world ', 20);
        $cjk = str_repeat('你好世界', 20);

        $this->assertGreaterThan(
            $calculator->estimate($latin),
            $calculator->estimate($cjk)
        );
    }

    public function test_estimates_code_more_densely_than_plain_latin_text(): void
    {
        $calculator = new TokenCalculator();

        $plain = str_repeat('this is a simple sentence ', 12);
        $code = str_repeat('public function handle(array $payload): string { return json_encode($payload); }', 6);

        $this->assertGreaterThan(
            $calculator->estimate($plain),
            $calculator->estimate($code)
        );
    }

    public function test_converts_token_limits_to_character_budgets_by_profile(): void
    {
        $calculator = new TokenCalculator();

        $this->assertLessThan(
            $calculator->charactersForTokens(1000, 'latin'),
            $calculator->charactersForTokens(1000, 'cjk')
        );
    }
}
