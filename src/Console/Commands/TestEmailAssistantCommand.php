<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Services\ActionService;

class TestEmailAssistantCommand extends Command
{
    protected $signature = 'ai:test-email-assistant 
                            {--session=email-assistant-001 : Session ID for conversation}
                            {--scenario=inbox : Test scenario (inbox|priority|calendar|attachment)}';

    protected $description = 'Test AI Email Assistant with attachments, prioritization, and calendar actions';

    public function handle(): int
    {
        $this->info('📧 AI Email Assistant Test');
        $this->newLine();

        $sessionId = $this->option('session');
        $scenario = $this->option('scenario');

        // Simulate email data
        $emailData = $this->getEmailData();

        switch ($scenario) {
            case 'inbox':
                $this->testInboxQuery($sessionId, $emailData);
                break;
            case 'priority':
                $this->testPriorityEmails($sessionId, $emailData);
                break;
            case 'calendar':
                $this->testCalendarActions($sessionId, $emailData);
                break;
            case 'attachment':
                $this->testAttachmentQuery($sessionId, $emailData);
                break;
            default:
                $this->runFullScenario($sessionId, $emailData);
        }

        return 0;
    }

    protected function runFullScenario(string $sessionId, array $emailData): void
    {
        $this->info('🎯 Running Full Email Assistant Scenario with Memory');
        $this->info('Session ID: ' . $sessionId);
        $this->newLine();

        $chatService = app(ChatService::class);
        $actionService = app(ActionService::class);
        $conversationService = app(\LaravelAIEngine\Services\ConversationService::class);

        // Clear any existing conversation for this session
        $this->info('🧹 Clearing previous session data...');
        $conversationService->clearConversation($sessionId);
        $this->newLine();

        // Step 1: Initialize with email context
        $this->step('1️⃣ Loading Email Context');
        $contextMessage = $this->buildEmailContext($emailData);
        
        $response = $chatService->processMessage(
            message: $contextMessage,
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );
        
        $this->displayResponse($response->getContent());
        $this->displayMemoryStatus($sessionId);
        $this->waitForUser();

        // Step 2: Ask about top emails (should remember context from step 1)
        $this->step('2️⃣ Asking About Top Priority Emails');
        $this->info('💭 Testing memory: AI should remember the emails from step 1');
        $this->newLine();
        
        $response = $chatService->processMessage(
            message: "What are my top 3 most important emails? Explain why each is important.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );
        
        $this->displayResponse($response->getContent());
        $this->displayMemoryStatus($sessionId);
        $this->waitForUser();

        // Step 3: Ask about attachments (should remember emails)
        $this->step('3️⃣ Querying Email Attachments');
        $this->info('💭 Testing memory: AI should remember which emails have attachments');
        $this->newLine();
        
        $response = $chatService->processMessage(
            message: "Which emails have attachments? What type of files are they?",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );
        
        $this->displayResponse($response->getContent());
        $this->displayMemoryStatus($sessionId);
        $this->waitForUser();

        // Step 4: Request calendar action (should remember meeting times)
        $this->step('4️⃣ Creating Calendar Events');
        $this->info('💭 Testing memory: AI should remember meeting times from emails');
        $this->newLine();
        
        $response = $chatService->processMessage(
            message: "Create calendar events for any meetings or deadlines mentioned in my emails. Use Google Calendar format.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );
        
        $this->displayResponse($response->getContent());
        
        // Generate suggested actions
        $actions = $actionService->generateSuggestedActions($response->getContent(), $sessionId);
        $this->displayActions($actions);
        $this->displayMemoryStatus($sessionId);
        $this->waitForUser();

        // Step 5: Follow-up action (should remember which email is most urgent)
        $this->step('5️⃣ Taking Action on Important Email');
        $this->info('💭 Testing memory: AI should remember which email is most urgent');
        $this->newLine();
        
        $response = $chatService->processMessage(
            message: "Draft a reply to the most urgent email that requires my attention.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );
        
        $this->displayResponse($response->getContent());
        $this->displayMemoryStatus($sessionId);

        // Step 6: Memory verification test
        $this->step('6️⃣ Memory Verification Test');
        $this->info('💭 Final memory test: Ask about something from the beginning');
        $this->newLine();
        
        $response = $chatService->processMessage(
            message: "What was the subject of the first email I showed you?",
            sessionId: $sessionId,
            useMemory: true,
            useActions: false
        );
        
        $this->displayResponse($response->getContent());
        $this->displayMemoryStatus($sessionId);

        $this->newLine();
        $this->info('✅ Full scenario completed successfully!');
        $this->displayFinalMemoryReport($sessionId);
    }

    protected function displayMemoryStatus(string $sessionId): void
    {
        $conversationService = app(\LaravelAIEngine\Services\ConversationService::class);
        $messages = $conversationService->getConversationHistory($sessionId, 100);
        
        $userMessages = array_filter($messages, fn($m) => ($m['role'] ?? '') === 'user');
        $assistantMessages = array_filter($messages, fn($m) => ($m['role'] ?? '') === 'assistant');
        
        $this->newLine();
        $this->line('📊 Memory Status:');
        $this->line('  Total messages: ' . count($messages));
        $this->line('  User messages: ' . count($userMessages));
        $this->line('  AI responses: ' . count($assistantMessages));
        $this->newLine();
    }

    protected function displayFinalMemoryReport(string $sessionId): void
    {
        $conversationService = app(\LaravelAIEngine\Services\ConversationService::class);
        $messages = $conversationService->getConversationHistory($sessionId, 100);
        
        $this->newLine();
        $this->info('📋 Final Memory Report');
        $this->line(str_repeat('═', 60));
        $this->line('Session ID: ' . $sessionId);
        $this->line('Total Messages: ' . count($messages));
        $this->newLine();
        
        if (!empty($messages)) {
            $this->line('Conversation Timeline:');
            foreach ($messages as $index => $message) {
                $role = strtoupper($message['role'] ?? 'unknown');
                $content = substr($message['content'] ?? '', 0, 60);
                $this->line(sprintf('  %2d. [%s] %s...', $index + 1, $role, $content));
            }
        }
        
        $this->line(str_repeat('═', 60));
    }

    protected function waitForUser(): void
    {
        if ($this->option('no-interaction')) {
            sleep(1);
            return;
        }
        
        $this->newLine();
        $this->comment('Press Enter to continue to next step...');
        fgets(STDIN);
    }

    protected function testInboxQuery(string $sessionId, array $emailData): void
    {
        $this->info('📥 Testing Inbox Query');
        $chatService = app(ChatService::class);

        $contextMessage = $this->buildEmailContext($emailData);
        $response = $chatService->processMessage(
            message: $contextMessage . "\n\nSummarize my inbox and tell me what needs immediate attention.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );

        $this->displayResponse($response->getContent());
    }

    protected function testPriorityEmails(string $sessionId, array $emailData): void
    {
        $this->info('⭐ Testing Priority Email Detection');
        $chatService = app(ChatService::class);

        $contextMessage = $this->buildEmailContext($emailData);
        $response = $chatService->processMessage(
            message: $contextMessage . "\n\nRank these emails by priority (1-5) and explain your reasoning.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );

        $this->displayResponse($response->getContent());
    }

    protected function testCalendarActions(string $sessionId, array $emailData): void
    {
        $this->info('📅 Testing Calendar Integration');
        $chatService = app(ChatService::class);

        $contextMessage = $this->buildEmailContext($emailData);
        $response = $chatService->processMessage(
            message: $contextMessage . "\n\nExtract all dates, times, and meeting information. Create Google Calendar event JSON for each.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );

        $this->displayResponse($response->getContent());
        
        // Parse and display calendar events
        $this->extractCalendarEvents($response->getContent());
    }

    protected function testAttachmentQuery(string $sessionId, array $emailData): void
    {
        $this->info('📎 Testing Attachment Analysis');
        $chatService = app(ChatService::class);

        $contextMessage = $this->buildEmailContext($emailData);
        $response = $chatService->processMessage(
            message: $contextMessage . "\n\nList all attachments, categorize them by type, and suggest which ones I should review first.",
            sessionId: $sessionId,
            useMemory: true,
            useActions: true
        );

        $this->displayResponse($response->getContent());
    }

    protected function buildEmailContext(array $emailData): string
    {
        $context = "I have the following emails in my inbox:\n\n";

        foreach ($emailData as $index => $email) {
            $context .= "Email #" . ($index + 1) . ":\n";
            $context .= "From: {$email['from']}\n";
            $context .= "Subject: {$email['subject']}\n";
            $context .= "Date: {$email['date']}\n";
            $context .= "Priority: {$email['priority']}\n";
            
            if (!empty($email['attachments'])) {
                $context .= "Attachments: " . implode(', ', $email['attachments']) . "\n";
            }
            
            $context .= "Preview: {$email['preview']}\n";
            $context .= "\n";
        }

        return $context;
    }

    protected function getEmailData(): array
    {
        return [
            [
                'from' => 'sarah.johnson@techcorp.com',
                'subject' => 'URGENT: Q4 Budget Review Meeting - Tomorrow 2PM',
                'date' => 'Today, 9:30 AM',
                'priority' => 'High',
                'attachments' => ['Q4_Budget_Report.pdf', 'Financial_Summary.xlsx'],
                'preview' => 'Hi, We need to finalize the Q4 budget by end of week. Please review the attached reports before our meeting tomorrow at 2 PM. The CFO will be joining us to discuss the $2M allocation for the new project...'
            ],
            [
                'from' => 'mike.chen@designstudio.io',
                'subject' => 'Website Redesign Mockups - Feedback Needed',
                'date' => 'Today, 11:15 AM',
                'priority' => 'Medium',
                'attachments' => ['Homepage_V2.fig', 'Mobile_Mockups.png', 'Design_System.pdf'],
                'preview' => 'Hey! I\'ve completed the initial mockups for the website redesign. Please check the attached Figma file and let me know your thoughts by Friday. We\'re aiming to start development next Monday...'
            ],
            [
                'from' => 'hr@company.com',
                'subject' => 'Annual Performance Review - Schedule Your Meeting',
                'date' => 'Yesterday, 3:45 PM',
                'priority' => 'Medium',
                'attachments' => ['Performance_Review_Form.docx'],
                'preview' => 'Dear Team Member, It\'s time for your annual performance review. Please fill out the attached self-assessment form and schedule a meeting with your manager between Dec 1-15...'
            ],
            [
                'from' => 'newsletter@techweekly.com',
                'subject' => 'Top 10 AI Tools for Developers in 2024',
                'date' => 'Yesterday, 8:00 AM',
                'priority' => 'Low',
                'attachments' => [],
                'preview' => 'This week\'s highlights: New AI coding assistants, Laravel 11 features, and the rise of edge computing. Plus, exclusive interviews with tech leaders...'
            ],
            [
                'from' => 'legal@vendor.com',
                'subject' => 'Contract Renewal - Action Required by Nov 30',
                'date' => '2 days ago',
                'priority' => 'High',
                'attachments' => ['Service_Agreement_2025.pdf', 'Pricing_Schedule.pdf'],
                'preview' => 'Dear Partner, Your annual service contract expires on November 30, 2024. Please review the attached renewal agreement and pricing schedule. We need your signature by the deadline to avoid service interruption...'
            ],
        ];
    }

    protected function step(string $title): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════');
        $this->info($title);
        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();
    }

    protected function displayResponse(string $content): void
    {
        $this->line('💬 AI Response:');
        $this->line('─────────────────────────────────────────────────');
        $this->line($content);
        $this->line('─────────────────────────────────────────────────');
        $this->newLine();
    }

    protected function displayActions(array $actions): void
    {
        if (empty($actions)) {
            return;
        }

        $this->info('🎯 Suggested Actions:');
        foreach ($actions as $action) {
            $this->line('  • ' . $action->label);
        }
        $this->newLine();
    }

    protected function extractCalendarEvents(string $content): void
    {
        // Try to extract JSON calendar events from response
        if (preg_match_all('/\{[^}]*"summary"[^}]*\}/s', $content, $matches)) {
            $this->info('📅 Extracted Calendar Events:');
            foreach ($matches[0] as $index => $eventJson) {
                $this->line('  Event ' . ($index + 1) . ': ' . $eventJson);
            }
            $this->newLine();
        }
    }
}
