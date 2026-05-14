<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Contracts\AgentRuntimeContract;

class TestAgentCommand extends Command
{
    protected $signature = 'ai:test-agent
                            {--message= : Message to send to agent}
                            {--session= : Session ID (default: random)}
                            {--user=1 : User ID}
                            {--engine=openai : AI engine to use}
                            {--model=gpt-4o : AI model to use}
                            {--debug : Enable debug mode}';

    protected $description = 'Test the Unified AI Agent system';

    public function handle(AgentRuntimeContract $runtime)
    {
        $this->info('🤖 Unified AI Agent Test');
        $this->newLine();

        $sessionId = $this->option('session') ?? 'agent-test-' . uniqid();
        $userId = $this->option('user');
        $message = $this->option('message');

        $this->info("Session ID: {$sessionId}");
        $this->info("User ID: {$userId}");
        $this->newLine();

        if (!$message) {
            $this->info('💬 Interactive Mode - Type "quit" to exit');
            $this->newLine();
            
            while (true) {
                $message = $this->ask('You');
                
                if (strtolower($message) === 'quit') {
                    $this->info('👋 Goodbye!');
                    break;
                }

                $this->processMessage($runtime, $message, $sessionId, $userId);
            }
        } else {
            $this->processMessage($runtime, $message, $sessionId, $userId);
        }

        return 0;
    }

    protected function processMessage(
        AgentRuntimeContract $runtime,
        string $message,
        string $sessionId,
        $userId
    ): void {
        $this->newLine();
        $this->line('⏳ Processing...');
        $this->newLine();

        $startTime = microtime(true);

        try {
            $response = $runtime->process(
                $message,
                $sessionId,
                $userId,
                [
                    'engine' => $this->option('engine'),
                    'model' => $this->option('model'),
                ]
            );

            $duration = round((microtime(true) - $startTime) * 1000);

            $this->displayResponse($response, $duration);

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            
            if ($this->option('debug')) {
                $this->error($e->getTraceAsString());
            }
        }
    }

    protected function displayResponse($response, int $duration): void
    {
        $this->info('─────────────────────────────────────────────────────────');
        $this->info('🤖 Agent Response:');
        $this->newLine();
        
        $this->line($response->message);
        $this->newLine();

        if ($response->strategy) {
            $strategyIcon = match($response->strategy) {
                'quick_action' => '⚡',
                'guided_flow' => '📝',
                'conversational' => '💬',
                default => '🔹',
            };
            
            $this->line("{$strategyIcon} Strategy: {$response->strategy}");
        }

        if ($response->needsUserInput) {
            $this->warn('⏸️  Waiting for user input');
            
            if ($response->actions) {
                $this->newLine();
                $this->line('Available actions:');
                foreach ($response->actions as $action) {
                    $this->line("  • {$action['label']}");
                }
            }
        }

        if ($response->isComplete) {
            $this->info('✅ Complete');
        }

        $this->newLine();
        $this->comment("⏱️  Duration: {$duration}ms");
        $this->info('─────────────────────────────────────────────────────────');
        $this->newLine();

        if ($this->option('debug') && $response->data) {
            $this->line('Debug Data:');
            $this->line(json_encode($response->data, JSON_PRETTY_PRINT));
            $this->newLine();
        }
    }
}
