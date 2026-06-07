<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\StructuredFileExtractor;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Generic field extraction: turns document text + a create tool's field list into a JSON
 * payload, restricted to the requested fields. The model call (generateDirect) is mocked,
 * so this is deterministic.
 */
class StructuredFileExtractorTest extends TestCase
{
    private function extractor(string $json): StructuredFileExtractor
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateDirect')->andReturn(AIResponse::success($json, 'openai', 'gpt-4o-mini'));

        return new StructuredFileExtractor($ai);
    }

    public function test_extracts_only_requested_fields(): void
    {
        $json = "```json\n" . json_encode([
            'customer_name' => 'Acme Corp',
            'customer_email' => 'ap@acme.test',
            'items' => [['product_name' => 'Widget', 'quantity' => 2]],
            'unexpected' => 'should be dropped',
        ]) . "\n```";

        $out = $this->extractor($json)->extract('an invoice document', ['customer_name', 'customer_email', 'items', 'confirmed']);

        $this->assertSame('Acme Corp', $out['customer_name']);
        $this->assertSame('ap@acme.test', $out['customer_email']);
        $this->assertCount(1, $out['items']);
        $this->assertArrayNotHasKey('unexpected', $out, 'fields outside the requested set are dropped.');
        $this->assertArrayNotHasKey('confirmed', $out, 'confirmed is never extracted from content.');
    }

    public function test_drops_null_and_empty_values(): void
    {
        $json = json_encode(['customer_name' => 'Acme', 'customer_email' => null, 'phone' => '']);
        $out = $this->extractor($json)->extract('doc', ['customer_name', 'customer_email', 'phone']);

        $this->assertSame(['customer_name' => 'Acme'], $out);
    }

    public function test_parses_raw_json_without_code_fence(): void
    {
        $out = $this->extractor('{"customer_name":"Globex"}')->extract('doc', ['customer_name']);
        $this->assertSame('Globex', $out['customer_name']);
    }

    public function test_empty_content_or_fields_makes_no_model_call(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generateDirect');
        $extractor = new StructuredFileExtractor($ai);

        $this->assertSame([], $extractor->extract('', ['customer_name']));
        $this->assertSame([], $extractor->extract('some content', []));
    }

    public function test_model_failure_returns_empty(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateDirect')->andThrow(new \RuntimeException('engine down'));

        $this->assertSame([], (new StructuredFileExtractor($ai))->extract('doc', ['customer_name']));
    }

    public function test_unparseable_response_returns_empty(): void
    {
        $out = $this->extractor('totally not json at all')->extract('doc', ['customer_name']);
        $this->assertSame([], $out);
    }
}
