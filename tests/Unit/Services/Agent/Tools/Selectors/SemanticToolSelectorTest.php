<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\Tools\Selectors\SemanticToolSelector;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class SemanticToolSelectorTest extends UnitTestCase
{
    /**
     * @param array<string, string> $nameToDescription
     * @return array<string, object>
     */
    private function tools(array $nameToDescription): array
    {
        $tools = [];
        foreach ($nameToDescription as $name => $description) {
            $tools[$name] = new class($name, $description) {
                public function __construct(private string $name, private string $description)
                {
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function getDescription(): string
                {
                    return $this->description;
                }
            };
        }

        return $tools;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai-agent.ai_native.tool_selection.always', ['search_knowledge']);
        config()->set('ai-agent.ai_native.tool_selection.limit', 3);
    }

    public function test_ranks_tools_by_embedding_similarity_and_keeps_core(): void
    {
        // Deterministic stand-in for embeddings: "invoice"/"customer" text -> [1,0], else [0,1].
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embed')->andReturnUsing(static function (string $text): array {
            $t = mb_strtolower($text);

            return (str_contains($t, 'invoice') || str_contains($t, 'customer')) ? [1.0, 0.0] : [0.0, 1.0];
        });
        $embeddings->shouldReceive('cosineSimilarity')->andReturnUsing(
            static fn (array $a, array $b): float => (float) array_sum(array_map(static fn ($x, $y) => $x * $y, $a, $b))
        );

        $tools = $this->tools([
            'create_invoice' => 'Create a draft invoice.',
            'find_customer' => 'Look up a customer.',
            'translate_text' => 'Translate text.',
            'send_email' => 'Send an email.',
            'search_knowledge' => 'Search the knowledge base.',
        ]);

        $selected = (new SemanticToolSelector($embeddings))->select($tools, 'create an invoice', [], []);
        $names = array_keys($selected);

        $this->assertContains('search_knowledge', $names);            // core
        $this->assertContains('create_invoice', $names);              // sim 1
        $this->assertContains('find_customer', $names);               // sim 1
        $this->assertNotContains('translate_text', $names);           // sim 0, beyond limit
        $this->assertLessThanOrEqual(3, count($selected));
    }

    public function test_fails_open_when_embedding_errors(): void
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embed')->andThrow(new \RuntimeException('embeddings down'));

        $tools = $this->tools([
            'create_invoice' => 'Create a draft invoice.',
            'send_email' => 'Send an email.',
            'search_knowledge' => 'Search the knowledge base.',
        ]);

        $this->assertSame($tools, (new SemanticToolSelector($embeddings))->select($tools, 'create an invoice', [], []));
    }
}
