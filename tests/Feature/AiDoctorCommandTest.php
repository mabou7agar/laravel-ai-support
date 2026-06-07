<?php

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Tests\TestCase;

class AiDoctorCommandTest extends TestCase
{
    public function test_doctor_runs_and_reports_capabilities(): void
    {
        $this->artisan('ai:doctor')
            ->expectsOutputToContain('AI Orchestrator Doctor')
            ->assertExitCode(0);
    }

    public function test_doctor_json_output_is_valid_and_structured(): void
    {
        $this->artisan('ai:doctor --json')->assertExitCode(0);

        $exit = $this->withoutMockingConsoleOutput()->artisan('ai:doctor --json');
        $this->assertSame(0, $exit);

        $output = \Illuminate\Support\Facades\Artisan::output();
        $json = json_decode($output, true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('counts', $json);
        $this->assertArrayHasKey('tools', $json['counts']);
        $this->assertArrayHasKey('warnings', $json);
        $this->assertArrayHasKey('config', $json);
    }

    public function test_fail_on_warning_exits_non_zero_when_warnings_present(): void
    {
        // A bare testbench has no data_query tool / collections, so warnings exist.
        $this->artisan('ai:doctor --fail-on-warning')->assertExitCode(1);
    }

    public function test_reports_engine_key_health_and_flags_keyless_fallbacks(): void
    {
        config()->set('ai-engine.default', 'openai');
        config()->set('ai-engine.engines.openai.api_key', 'sk-present');
        config()->set('ai-engine.engines.gemini.api_key', '');
        config()->set('ai-engine.error_handling.fallback_engines', [
            'openai' => ['gemini'],
        ]);

        $this->withoutMockingConsoleOutput()->artisan('ai:doctor --json');
        $json = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertArrayHasKey('engines', $json);
        $this->assertTrue($json['engines']['openai']);
        $this->assertFalse($json['engines']['gemini'], 'a keyless engine is reported as not configured.');

        $keylessWarning = array_filter($json['warnings'], static fn ($w): bool => str_contains($w, 'no API key') && str_contains($w, 'gemini'));
        $this->assertNotEmpty($keylessWarning, 'a keyless fallback engine must be flagged.');
    }
}
