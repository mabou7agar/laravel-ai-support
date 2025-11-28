<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class TestAiChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ai:test-chat
                            {--session= : Session ID for conversation}
                            {--message= : Message to send}
                            {--memory : Enable conversation memory}
                            {--actions : Enable interactive actions}';

    /**
     * The console command description.
     */
    protected $description = 'Test AI Chat functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🤖 Testing AI Chat Controller Functionality');
        $this->newLine();

        // Get or generate session ID
        $sessionId = $this->option('session') ?? 'test-' . uniqid();
        $useMemory = $this->option('memory');
        $useActions = $this->option('actions');

        $this->info("Session ID: {$sessionId}");
        $this->info("Memory: " . ($useMemory ? 'Enabled' : 'Disabled'));
        $this->info("Actions: " . ($useActions ? 'Enabled' : 'Disabled'));
        $this->newLine();

        // Get message
        $message = $this->option('message') ?? $this->ask('Enter your message');

        if (!$message) {
            $this->error('No message provided');
            return 1;
        }

        try {
            // Test 1: Create AI Request
            $this->info('📝 Creating AI Request...');
            $aiRequest = Engine::createRequest(
                prompt: $message,
                engine: 'openai',
                model: 'gpt-4o-mini',
                maxTokens: 1000,
                temperature: 0.7,
                systemPrompt: 'You are a helpful AI assistant.'
            );
            $this->info('✅ AI Request created successfully');
            $this->newLine();

            // Test 2: Set Conversation ID (if memory enabled)
            if ($useMemory) {
                $this->info('🧠 Setting up conversation memory...');
                
                // Check if conversation exists by title
                $existingConv = \DB::table('ai_conversations')
                    ->where('title', $sessionId)
                    ->first();
                
                if ($existingConv) {
                    $conversationId = $existingConv->conversation_id;
                    $aiRequest->setConversationId($conversationId);
                    
                    // Load conversation history
                    $conversation = Engine::memory()->getConversation($conversationId);
                    if ($conversation && isset($conversation['messages'])) {
                        $messageCount = count($conversation['messages']);
                        $this->info("✅ Loaded {$messageCount} previous messages");
                        
                        // Show last 3 messages
                        if ($messageCount > 0) {
                            $this->info('Last messages:');
                            $lastMessages = array_slice($conversation['messages'], -3);
                            foreach ($lastMessages as $msg) {
                                $role = $msg['role'] ?? 'unknown';
                                $content = substr($msg['content'] ?? '', 0, 50);
                                $this->line("  [{$role}] {$content}...");
                            }
                        }
                        
                        // Add to request
                        $messages = array_slice($conversation['messages'], -20);
                        $aiRequest->withMessages($messages);
                    }
                } else {
                    $this->info('✅ No previous conversation found (new session)');
                }
                $this->newLine();
            }

            // Test 3: Generate AI Response
            $this->info('🚀 Generating AI response...');
            $startTime = microtime(true);
            
            $aiEngineService = app(\LaravelAIEngine\Services\AIEngineService::class);
            $response = $aiEngineService->generate($aiRequest);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("✅ Response generated in {$duration}ms");
            $this->newLine();

            // Test 4: Display Response
            $this->info('💬 AI Response:');
            $this->line('─────────────────────────────────────────────────');
            $this->line($response->getContent());
            $this->line('─────────────────────────────────────────────────');
            $this->newLine();

            // Test 5: Display Usage Stats
            if ($response->getUsage()) {
                $this->info('📊 Token Usage:');
                $usage = $response->getUsage();
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Input Tokens', $usage['prompt_tokens'] ?? 0],
                        ['Output Tokens', $usage['completion_tokens'] ?? 0],
                        ['Total Tokens', $usage['total_tokens'] ?? 0],
                    ]
                );
                $this->newLine();
            }

            // Test 6: Save to Memory (if enabled)
            if ($useMemory) {
                $this->info('💾 Saving to conversation memory...');
                
                // Create or get conversation
                $conversationManager = app(\LaravelAIEngine\Services\ConversationManager::class);
                
                try {
                    // Check if conversation exists by title
                    $existing = \DB::table('ai_conversations')
                        ->where('title', $sessionId)
                        ->first();
                    
                    if (!$existing) {
                        // Create new conversation
                        $conversation = $conversationManager->createConversation(
                            userId: null,
                            title: $sessionId,
                            systemPrompt: 'You are a helpful AI assistant.',
                            settings: [
                                'engine' => 'openai',
                                'model' => 'gpt-4o-mini',
                            ]
                        );
                        $conversationId = $conversation->conversation_id;
                        $this->info("✨ Created new conversation (ID: {$conversationId})");
                    } else {
                        $conversationId = $existing->conversation_id;
                        $this->info("📖 Using existing conversation (ID: {$conversationId})");
                    }
                    
                    // Add messages using the auto-generated conversation_id
                    $conversationManager->addUserMessage($conversationId, $message);
                    $conversationManager->addAssistantMessage($conversationId, $response->getContent(), $response);
                    
                    $this->info('✅ Messages saved to conversation');
                    $this->newLine();
                    
                } catch (\Exception $e) {
                    $this->error('Failed to save conversation: ' . $e->getMessage());
                    $this->newLine();
                }
            }

            // Test 7: Generate Actions (if enabled)
            if ($useActions) {
                $this->info('🎯 Generating interactive actions...');
                $actions = $this->generateActions($response->getContent(), $sessionId);
                if (!empty($actions)) {
                    $this->info("✅ Generated " . count($actions) . " actions:");
                    foreach ($actions as $action) {
                        $this->line("  • {$action['label']}");
                    }
                } else {
                    $this->info('ℹ️  No actions generated');
                }
                $this->newLine();
            }

            $this->info('✅ All tests completed successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Generate interactive actions
     */
    protected function generateActions(string $content, string $sessionId): array
    {
        $actions = [];

        // Add regenerate action
        $actions[] = [
            'id' => 'regenerate_' . uniqid(),
            'type' => 'button',
            'label' => '🔄 Regenerate',
            'data' => ['action' => 'regenerate', 'session_id' => $sessionId]
        ];

        // Add copy action
        $actions[] = [
            'id' => 'copy_' . uniqid(),
            'type' => 'button',
            'label' => '📋 Copy',
            'data' => ['action' => 'copy', 'content' => $content]
        ];

        // Add quick replies based on content
        if (stripos($content, 'question') !== false || stripos($content, '?') !== false) {
            $actions[] = [
                'id' => 'more_' . uniqid(),
                'type' => 'quick_reply',
                'label' => 'Tell me more',
                'data' => ['reply' => 'Can you tell me more about that?']
            ];
        }

        return $actions;
    }
}
