<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\Tools\Selectors\KeywordToolSelector;
use LaravelAIEngine\Tests\UnitTestCase;

class KeywordToolSelectorTest extends UnitTestCase
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

    public function test_selects_tools_overlapping_the_message_plus_core(): void
    {
        $tools = $this->tools([
            'create_invoice' => 'Create a draft invoice.',
            'find_customer' => 'Look up a customer by email.',
            'send_email' => 'Send a marketing email.',
            'translate_text' => 'Translate text between languages.',
            'search_knowledge' => 'Search the knowledge base.',
        ]);

        $selected = array_keys((new KeywordToolSelector())->select($tools, 'create an invoice for a customer', [], []));

        $this->assertContains('create_invoice', $selected);  // name hit "invoice"
        $this->assertContains('find_customer', $selected);   // name hit "customer"
        $this->assertContains('search_knowledge', $selected); // always-core
        $this->assertNotContains('translate_text', $selected);
        $this->assertNotContains('send_email', $selected);
    }

    public function test_respects_the_limit(): void
    {
        $tools = $this->tools([
            'invoice_a' => 'invoice', 'invoice_b' => 'invoice', 'invoice_c' => 'invoice',
            'invoice_d' => 'invoice', 'search_knowledge' => 'kb',
        ]);

        // limit 3, core takes 1 slot -> at most 2 candidates.
        $selected = (new KeywordToolSelector())->select($tools, 'invoice invoice', [], []);

        $this->assertLessThanOrEqual(3, count($selected));
        $this->assertArrayHasKey('search_knowledge', $selected);
    }

    public function test_falls_back_to_all_when_nothing_matches(): void
    {
        $tools = $this->tools([
            'create_invoice' => 'Create a draft invoice.',
            'find_customer' => 'Look up a customer.',
            'search_knowledge' => 'Search the knowledge base.',
        ]);

        // A greeting has no meaningful terms -> expose everything rather than starve.
        $this->assertSame($tools, (new KeywordToolSelector())->select($tools, 'Hi there', [], []));
    }
}
