<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Learning;

use LaravelAIEngine\Tests\TestCase;

class LearnCommandTest extends TestCase
{
    public function test_ai_learn_command_ingests_text_and_returns_json(): void
    {
        $this->artisan('ai:learn', [
            'source' => 'Design cards should use clear hierarchy and compact spacing.',
            '--type' => 'design',
            '--workspace' => 'workspace-1',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_learn_sources', [
            'source_type' => 'text',
            'type' => 'design',
            'workspace_id' => 'workspace-1',
        ]);
    }
}
