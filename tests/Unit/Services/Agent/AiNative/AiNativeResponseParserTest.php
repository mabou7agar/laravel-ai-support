<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseParser;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * Guards the plan parser — especially the embedded-plan extraction: models
 * routinely emit prose followed by the plan JSON, and dumping that mixed
 * content raw (JSON blob included) into the user-facing final message was a
 * live-reported bug in host applications.
 */
class AiNativeResponseParserTest extends UnitTestCase
{
    private AiNativeResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AiNativeResponseParser();
    }

    public function test_pure_json_final_plan_parses_unchanged(): void
    {
        $plan = $this->parser->parse('{"action":"final","message":"All done.","data":{"id":7}}');

        $this->assertSame('final', $plan['action']);
        $this->assertSame('All done.', $plan['message']);
        $this->assertSame(['id' => 7], $plan['data']);
    }

    public function test_prose_followed_by_final_plan_extracts_the_plan_not_the_blob(): void
    {
        $content = "I've staged the move as requested.\n\n"
            . '{"action":"final","message":"Move staged — apply it on the canvas.","data":{"preview_id":"2037"}}';

        $plan = $this->parser->parse($content);

        $this->assertSame('final', $plan['action']);
        $this->assertSame('Move staged — apply it on the canvas.', $plan['message']);
        $this->assertSame(['preview_id' => '2037'], $plan['data']);
        $this->assertStringNotContainsString('{"action"', (string) $plan['message']);
    }

    public function test_prose_followed_by_tool_call_plan_extracts_the_tool_call(): void
    {
        $content = 'Let me remove that section for you. '
            . '{"action":"tool_call","tool":"reorder_remove_sections","arguments":{"patch_ops":[{"op":"remove","type":"stats"}]}}';

        $plan = $this->parser->parse($content);

        $this->assertSame('tool_call', $plan['action']);
        $this->assertSame('reorder_remove_sections', $plan['tool']);
        $this->assertSame(['op' => 'remove', 'type' => 'stats'], $plan['arguments']['patch_ops'][0]);
    }

    public function test_embedded_plan_without_message_falls_back_to_the_prose(): void
    {
        $plan = $this->parser->parse('The pricing section was moved up. {"action":"final","data":{}}');

        $this->assertSame('final', $plan['action']);
        $this->assertSame('The pricing section was moved up.', $plan['message']);
    }

    public function test_braces_inside_json_strings_do_not_break_extraction(): void
    {
        $plan = $this->parser->parse('Note: {"action":"final","message":"Use {tokens} like {color} here."}');

        $this->assertSame('final', $plan['action']);
        $this->assertSame('Use {tokens} like {color} here.', $plan['message']);
    }

    public function test_plain_prose_stays_a_final_message(): void
    {
        $plan = $this->parser->parse('Everything looks good — nothing to change.');

        $this->assertSame('final', $plan['action']);
        $this->assertSame('Everything looks good — nothing to change.', $plan['message']);
    }

    public function test_non_plan_json_inside_prose_is_left_as_prose(): void
    {
        $content = 'Set the config to {"color": "blue"} and save.';

        $plan = $this->parser->parse($content);

        $this->assertSame('final', $plan['action']);
        $this->assertSame($content, $plan['message']);
    }

    public function test_empty_content_asks_for_more_information(): void
    {
        $this->assertSame('ask_user', $this->parser->parse('   ')['action']);
    }
    public function test_markdown_tool_call_narration_is_salvaged_not_dumped(): void
    {
        // The exact shape reported in production: the model wrote the tool call
        // as markdown (name in prose, args-only JSON) instead of the JSON action.
        $content = "**Tool Call:** theme_builder_add_section\n```json\n{\"family\": \"hero\"}\n```";
        $plan = $this->parser->parse($content);

        $this->assertSame('tool_call', $plan['action']);
        $this->assertSame('theme_builder_add_section', $plan['tool']);
        $this->assertSame(['family' => 'hero'], $plan['arguments']);
        // The raw markdown must NOT leak into a user-facing message.
        $this->assertStringNotContainsString('Tool Call', (string) ($plan['message'] ?? ''));
    }

    public function test_inline_tool_narration_with_arguments_label_is_salvaged(): void
    {
        $content = '**Tool Call**: theme_builder_add_section | **Arguments**: {"family":"cta"}';
        $plan = $this->parser->parse($content);

        $this->assertSame('tool_call', $plan['action']);
        $this->assertSame('theme_builder_add_section', $plan['tool']);
        $this->assertSame(['family' => 'cta'], $plan['arguments']);
    }

    public function test_prose_without_a_tool_cue_stays_a_final_message(): void
    {
        // A normal reply that merely mentions a snake_case word must not be
        // mis-salvaged into a tool call.
        $content = 'I updated the hero_section copy as you asked.';
        $plan = $this->parser->parse($content);

        $this->assertSame('final', $plan['action']);
        $this->assertSame($content, $plan['message']);
    }

    public function test_embedded_full_plan_still_wins_over_salvage(): void
    {
        // When the JSON itself is a proper plan, the embedded-plan path handles it.
        $content = 'Here is the plan: {"action":"tool_call","tool":"do_x","arguments":{"a":1}}';
        $plan = $this->parser->parse($content);

        $this->assertSame('tool_call', $plan['action']);
        $this->assertSame('do_x', $plan['tool']);
    }
}
